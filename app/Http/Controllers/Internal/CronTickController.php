<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\MlService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Phase E — the free tier has no persistent queue worker and no schedule:run
 * runner (see docs/DEPLOYMENT.md for the full reliability writeup). This is
 * hit every 10 minutes during business hours by .github/workflows/keep-alive.yml,
 * guarded by VerifyCronToken. One request does both jobs at once: running due
 * scheduled tasks, then draining whatever's pending on the ml/default queues.
 * The request itself is also what keeps the Laravel service warm — no
 * separate ping is needed.
 */
class CronTickController extends Controller
{
    public function __invoke()
    {
        // Overall wall-clock deadline for THIS ENTIRE REQUEST — schedule:run
        // plus queue:work together — not just the queue drain. Previously
        // only queue:work had a --max-time, evaluated BETWEEN jobs, never
        // during one: a chunk that was already running when the check fired
        // could still push the whole request past nginx's 90s
        // fastcgi_read_timeout and curl's 85s --max-time in
        // .github/workflows/keep-alive.yml (confirmed in production: the
        // workflow failing every run with curl exit 28, "0 bytes received"
        // after 85s — RequeueUnscoredSeniors dispatches up to 8 ProcessMlBatch
        // chunks per tick, and one 25-senior chunk measured ~35-40s on
        // Render's free tier — see docs/DEPLOYMENT.md §12b).
        $deadline = microtime(true) + (int) config('services.python.cron_budget', 70);

        Artisan::call('schedule:run');
        $scheduleOutput = Artisan::output();

        // Reserve cron_job_headroom out of whatever's left so a job already
        // running when queue:work's own --max-time check fires (only
        // evaluated BETWEEN jobs) can't run the response past $deadline —
        // see the class docblock above and MlService::coldStartTimeoutForCurrentContext()
        // for the matching cold-start-side budget.
        $headroom = (int) config('services.python.cron_job_headroom', 45);
        $maxTime = (int) floor($deadline - microtime(true)) - $headroom;

        if ($maxTime <= 0) {
            // schedule:run alone used the whole tick budget (or came close
            // enough that starting a job risks the same timeout this exists
            // to prevent). Skip the drain — nothing is lost, the next
            // 10-minute tick picks up any pending work.
            return response()->json([
                'ok' => true,
                'ran_at' => now()->toDateTimeString(),
                'schedule_output' => trim($scheduleOutput),
                'queue_output' => '(skipped — schedule:run used the full tick budget)',
            ]);
        }

        // Same named lock DrainsMlQueue::drainQueueAfterResponse() serializes
        // every other drain on (bulk upload, manual batch run, batchStatus()'s
        // poll-triggered top-up) — without it, a cron tick could run
        // queue:work concurrently with one of those, producing the
        // duplicated-chunk contention against the single-threaded inference
        // service that lock was added to prevent. No-ops (skips the drain,
        // not the whole tick) if another drain already holds it — that drain
        // is already making progress on the same queue.
        $lock = Cache::lock('ml:queue-drain', $maxTime + 60);
        if (! $lock->get()) {
            return response()->json([
                'ok' => true,
                'ran_at' => now()->toDateTimeString(),
                'schedule_output' => trim($scheduleOutput),
                'queue_output' => '(skipped — another drain already holds the lock)',
            ]);
        }

        // No --tries override: each job already sets its own safe $tries,
        // and queue.php's retry_after (600s) is deliberately kept above every
        // job's $timeout (300s) — overriding tries/timeout here would fight
        // that invariant.
        try {
            $queueOutput = MlService::runInCronDrain(function () use ($maxTime): string {
                Artisan::call('queue:work', [
                    '--queue' => 'ml,default',
                    '--stop-when-empty' => true,
                    '--max-time' => $maxTime,
                ]);

                return Artisan::output();
            });
        } finally {
            $lock->release();
        }

        return response()->json([
            'ok' => true,
            'ran_at' => now()->toDateTimeString(),
            'schedule_output' => trim($scheduleOutput),
            'queue_output' => trim($queueOutput),
        ]);
    }
}
