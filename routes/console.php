<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Reconciliation sweeper for stranded ML scoring (Phase E — see
// .github/workflows/keep-alive.yml, which fires schedule:run every 10
// minutes inside the 6am-8pm PHT business-hours window, same as everywhere
// else in this file). A bulk upload or Batch Assessment run can outlive
// every queue-drain time budget on a slow/free-tier ML service and leave
// seniors permanently unscored with nothing left to re-queue them — see
// RequeueUnscoredSeniors' own doc comment for the full failure mode.
// withoutOverlapping guards against a slow sweep still running when the
// next tick fires.
Schedule::command('ml:requeue-unscored --limit=200')
    ->everyTenMinutes()
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/ml-requeue.log'));

// Daily cluster snapshot (records cluster composition for longitudinal tracking).
// Was 23:55 — moved into the 6am-8pm PHT business-hours window (Phase E,
// .github/workflows/keep-alive.yml) since nothing runs schedule:run outside
// it in production (no persistent scheduler on the free tier). All three
// times below are on 10-minute boundaries to line up with the trigger's cadence.
Schedule::command('osca:snapshot-clusters')
    ->dailyAt('06:00')
    ->appendOutputTo(storage_path('logs/snapshot.log'));

// Daily GIS road-route backfill — caches ORS road distances for the nearest
// facility per type. It resumes where it left off (skips fresh-cached pairs)
// and self-limits, so coverage completes over a few days within the ORS
// free-tier daily quota without manual re-runs. withoutOverlapping guards
// against a slow run still going when the next fires. Was 05:00 — same
// business-hours-window reason as above; kept sequential, still before
// score-proximity.
Schedule::command('gis:cache-route-distances --facilities=12')
    ->dailyAt('06:10')
    ->withoutOverlapping(120)
    ->appendOutputTo(storage_path('logs/gis-route-cache.log'));

// After the backfill, refresh accessibility scores so newly-cached seniors flip
// from straight-line to road-based distance (local, fast). Was 06:00 — pushed
// 20 min later (same business-hours-window reason) so it still follows the
// route backfill above.
Schedule::command('gis:score-proximity')
    ->dailyAt('06:20')
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/gis-score.log'));
