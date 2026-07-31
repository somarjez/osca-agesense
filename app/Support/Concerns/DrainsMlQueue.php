<?php

namespace App\Support\Concerns;

use Illuminate\Support\Facades\Artisan;

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
     */
    protected function drainQueueAfterResponse(int $maxTime): void
    {
        if (! config('services.python.immediate_queue_drain') || app()->environment('testing')) {
            return;
        }

        dispatch(function () use ($maxTime) {
            Artisan::call('queue:work', [
                '--queue' => 'ml,default',
                '--stop-when-empty' => true,
                '--max-time' => $maxTime,
            ]);
        })->afterResponse();
    }
}
