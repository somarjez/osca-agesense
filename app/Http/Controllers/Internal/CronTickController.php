<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Artisan;

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
        Artisan::call('schedule:run');
        $scheduleOutput = Artisan::output();

        // --stop-when-empty: process everything currently pending then exit
        // cleanly (no daemon, safe to run once per HTTP request).
        // --max-time=240: caps a single invocation so a large backlog can't
        // run indefinitely within one request — the next 10-minute tick picks
        // up where this one left off. No --tries override: each job already
        // sets its own safe $tries, and queue.php's retry_after (600s) is
        // deliberately kept above every job's $timeout (300s) — overriding
        // tries/timeout here would fight that invariant.
        Artisan::call('queue:work', [
            '--queue' => 'ml,default',
            '--stop-when-empty' => true,
            '--max-time' => 240,
        ]);
        $queueOutput = Artisan::output();

        return response()->json([
            'ok' => true,
            'ran_at' => now()->toDateTimeString(),
            'schedule_output' => trim($scheduleOutput),
            'queue_output' => trim($queueOutput),
        ]);
    }
}
