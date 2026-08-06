<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMlBatch;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ml:requeue-unscored
 *
 * Reconciliation sweeper for the queue-drain time budget problem: a large
 * bulk upload or manual Batch Assessment run seeds many ProcessMlBatch
 * chunks, but DrainsMlQueue::drainQueueAfterResponse() and the cron tick
 * (CronTickController) each only work the queue for a bounded number of
 * seconds per invocation. On a slow/free-tier ML service, a big batch can
 * outlive every one of those budgets — confirmed root cause of a 360-row
 * import that only scored the first 100 seniors before the drains ran out
 * of time. Nothing else in the system ever re-queues a senior left behind
 * like that; Bus::batch(...)->allowFailures() plus ProcessMlBatch::failed()
 * clearing ml_queued_at means a stranded chunk stays stranded forever.
 *
 * This command finds active seniors with a processed QoL survey but no
 * usable MlResult and requeues them — same dispatch shape as
 * MlController::batchRun(). Run on a schedule (see routes/console.php) so a
 * stalled import self-heals within one keep-alive tick instead of requiring
 * a manual re-run.
 *
 * Gated on there being no currently-active Bus::batch() (see the
 * job_batches check below) rather than a fixed "how long is too long"
 * window on ml_queued_at. A large import legitimately drains slowly — 1000
 * seniors is 40 chunks at ~35-40s each, so ~60-70 minutes if only the
 * 10-minute cron tick is draining it — and a fixed window short enough to
 * catch a genuinely abandoned chunk would misfire on that import while it's
 * still honestly in progress, re-dispatching duplicates of seniors whose
 * original chunk simply hasn't been reached yet. Waiting for every active
 * batch to finish (finished_at/cancelled_at both null = still running) before
 * sweeping avoids that regardless of import size; a duplicate dispatch would
 * be wasteful rather than harmful either way (findReusableResult() makes a
 * second computation of an already-scored senior a no-op), but there's no
 * reason to risk it.
 */
class RequeueUnscoredSeniors extends Command
{
    protected $signature = 'ml:requeue-unscored
                            {--limit=200 : Maximum number of seniors to requeue in this run}
                            {--dry-run   : Report what would be requeued without dispatching anything}';

    protected $description = 'Find active seniors with a survey but no usable ML result and requeue them for scoring.';

    public function handle(MlService $ml): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        // Primary correctness gate: if any Bus::batch() is still running
        // (bulk upload, a manual Batch Assessment run, or a previous sweep),
        // every currently-unscored senior could simply be waiting their turn
        // in it — leave them alone and let it finish, no matter how long
        // that takes. Only once the queue is fully idle does "still
        // unscored" reliably mean "actually stranded" (its batch already
        // finished/was cancelled and this senior never got scored — e.g. a
        // chunk that exhausted both of ProcessMlBatch's retries).
        if (DB::table('job_batches')->whereNull('finished_at')->whereNull('cancelled_at')->exists()) {
            $this->info('An ML batch is already in progress — skipping this sweep cycle.');

            return self::SUCCESS;
        }

        $candidates = SeniorCitizen::active()
            ->whereHas('latestQolSurvey', fn ($q) => $q->where('status', 'processed'))
            // Defensive-only: catches the narrow window between a dispatch
            // setting ml_queued_at and its job_batches row becoming visible
            // to the query above. The active-batch gate above is what
            // actually makes this safe for a slow, large import.
            ->where(function ($q) {
                $q->whereNull('ml_queued_at')->orWhere('ml_queued_at', '<', now()->subMinutes(2));
            })
            ->with('latestQolSurvey')
            ->orderBy('id')
            ->get();

        // findReusableResult() is the pipeline's own single source of truth
        // for "does this senior still need scoring?" (runBatchPipeline()
        // checks every item against it before sending anything to Python).
        // Reusing it here rather than writing a second staleness rule keeps
        // the sweeper's definition of "unscored" identical to the pipeline's.
        $seniorIds = $candidates
            ->filter(fn ($senior) => $senior->latestQolSurvey
                && $ml->findReusableResult($senior, $senior->latestQolSurvey) === null)
            ->pluck('id')
            ->take($limit)
            ->values()
            ->all();

        if (empty($seniorIds)) {
            $this->info('No stranded/unscored seniors found.');

            return self::SUCCESS;
        }

        $this->info(count($seniorIds).' senior(s) need scoring.');

        if ($dryRun) {
            $this->line('Dry run — nothing dispatched. IDs: '.implode(', ', $seniorIds));

            return self::SUCCESS;
        }

        // Same reload-survival marker as MlController::batchRun()/
        // BulkUploadController::upload() — set before dispatch so a fresh
        // page load shows these seniors as "still processing".
        SeniorCitizen::whereIn('id', $seniorIds)->update(['ml_queued_at' => now()]);

        $cacheKey = 'ml_batch_'.now()->format('YmdHis');
        $chunks = array_chunk($seniorIds, (int) config('services.python.batch_chunk_size', 25));
        $jobs = array_map(fn ($chunk) => new ProcessMlBatch($chunk, $cacheKey), $chunks);

        $batch = Bus::batch($jobs)
            ->name('ML Batch — Requeue Sweep — '.now()->format('Y-m-d H:i'))
            ->allowFailures()
            ->dispatch();

        Cache::put("{$cacheKey}:batch_id", $batch->id, now()->addHours(2));
        Cache::put("{$cacheKey}:total", count($seniorIds), now()->addHours(2));
        Cache::put("{$cacheKey}:processed", 0, now()->addHours(2));
        Cache::put("{$cacheKey}:failed", 0, now()->addHours(2));
        Cache::put("{$cacheKey}:fallback", 0, now()->addHours(2));

        // Safe to unconditionally claim the resumable-batch slot the Batch
        // Assessment page reads — the active-batch gate above already
        // confirmed nothing else is running.
        Cache::put('ml_current_batch', ['cache_key' => $cacheKey, 'batch_id' => $batch->id], now()->addHours(2));

        $this->info("Dispatched batch {$batch->id} with ".count($chunks).' chunk(s).');

        return self::SUCCESS;
    }
}
