<?php

namespace App\Support\Concerns;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

trait DrainsMlQueue
{
    /**
     * Kick a bounded, non-blocking queue drain right after this request's
     * HTTP response has already been sent to the browser (Illuminate defers
     * afterResponse() dispatches until fastcgi_finish_request(), which this
     * Nginx+PHP-FPM stack supports). On Render's free tier the only other
     * drain is CronTickController's 10-minute cron tick — this closes the gap
     * for the common case (one senior, or a small batch) without adding a
     * paid persistent worker. $maxTime bounds the worst case so a large
     * backlog can't tie up a PHP-FPM worker indefinitely; the cron tick picks
     * up whatever this run doesn't finish, exactly as it already does for
     * itself. A no-op when ML_IMMEDIATE_QUEUE_DRAIN=false or during tests.
     *
     * Serialized across every caller (bulk upload, manual batch run,
     * batchStatus()'s poll-triggered top-up) via a single named lock held for
     * this drain's whole lifetime — not just a short throttle window. A
     * throttle that expires before a chunk finishes (the previous approach)
     * let a second drain start on top of a still-running one; on a large
     * bulk import that produced several concurrent `queue:work` processes
     * fighting over one single-threaded inference service, which is the
     * duplicated-chunk pattern documented in MlService::postWithColdStartRetry().
     * Cache::lock() is atomic on both the `database` and `file` cache stores
     * (the two this app actually runs on), so this holds on Render and
     * locally. If the lock is already held, this drain simply no-ops — the
     * drain that holds it is already making progress on the same queue.
     */
    protected function drainQueueAfterResponse(int $maxTime): void
    {
        if (! config('services.python.immediate_queue_drain') || app()->environment('testing')) {
            return;
        }

        dispatch(function () use ($maxTime) {
            $lock = Cache::lock('ml:queue-drain', $maxTime + 60);

            if (! $lock->get()) {
                return;
            }

            try {
                Artisan::call('queue:work', [
                    '--queue' => 'ml,default',
                    '--stop-when-empty' => true,
                    '--max-time' => $maxTime,
                ]);
            } finally {
                $lock->release();
            }
        })->afterResponse();
    }
}
