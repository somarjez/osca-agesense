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
        // --max-time=60: caps a single invocation so a large backlog can't
        // run indefinitely within one request — the next 10-minute tick picks
        // up where this one left off. This used to be 240s, which ignored the
        // reverse-proxy chain in front of this endpoint: nginx's
        // fastcgi_read_timeout (conf/nginx/nginx-site.conf) is 90s, and
        // Cloudflare's own hard proxy timeout is a non-configurable 100s —
        // both sit BELOW 240s, so once there was enough queued work to make
        // this request legitimately run that long, nginx or Cloudflare killed
        // the connection first and the whole tick was reported as a failure
        // (confirmed in production: 504 "upstream timed out" from nginx, and
        // 499 "client prematurely closed connection" from Cloudflare's own
        // cutoff, both on this route). 60s leaves real headroom under the 90s
        // ceiling for schedule:run's own time plus request/response overhead.
        //
        // This does NOT fully close the failure mode: --max-time only checks
        // BETWEEN jobs, not within one, and a single ML job whose inference
        // call hits a sleeping Python service can legitimately block for up
        // to services.python.cold_start_timeout (180s by default — see
        // MlService::postWithColdStartRetry) before this check ever gets a
        // chance to run. That residual case needs a separate fix (e.g. jobs
        // detecting they're running under a time-boxed drain and skipping the
        // cold-start retry) and is deliberately not attempted here.
        //
        // No --tries override: each job already sets its own safe $tries,
        // and queue.php's retry_after (600s) is deliberately kept above every
        // job's $timeout (300s) — overriding tries/timeout here would fight
        // that invariant.
        Artisan::call('queue:work', [
            '--queue' => 'ml,default',
            '--stop-when-empty' => true,
            '--max-time' => 60,
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
