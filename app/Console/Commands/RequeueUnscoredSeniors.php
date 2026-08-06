<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMlBatch;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

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
 * usable MlResult, excludes anything that looks genuinely in-flight, and
 * requeues the rest — same dispatch shape as MlController::batchRun(). Run
 * on a schedule (see routes/console.php) so a stalled import self-heals
 * within one keep-alive tick instead of requiring a manual re-run.
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

        // Anything queued within the last 15 minutes is presumed to be a
        // genuine in-flight chunk, not stranded — ProcessMlBatch clears
        // ml_queued_at unconditionally in both handle() and failed(), so a
        // marker that survives past a chunk's own $timeout (300s) plus a
        // safety margin means the worker that owned it is gone (deploy,
        // crash, OOM) and it is safe to requeue without racing a still-
        // running attempt.
        $cutoff = now()->subMinutes(15);

        $candidates = SeniorCitizen::active()
            ->whereHas('latestQolSurvey', fn ($q) => $q->where('status', 'processed'))
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('ml_queued_at')->orWhere('ml_queued_at', '<', $cutoff);
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

        // Only claim the resumable-batch slot the Batch Assessment page reads
        // if nothing else is already using it — a manual run or bulk upload
        // already in progress should keep showing on that page rather than
        // being silently swapped out by this background sweep.
        $current = Cache::get('ml_current_batch');
        $currentStillRunning = $current
            && Bus::findBatch($current['batch_id'])?->finished() === false;

        if (! $currentStillRunning) {
            Cache::put('ml_current_batch', ['cache_key' => $cacheKey, 'batch_id' => $batch->id], now()->addHours(2));
        }

        $this->info("Dispatched batch {$batch->id} with ".count($chunks).' chunk(s).');

        return self::SUCCESS;
    }
}
