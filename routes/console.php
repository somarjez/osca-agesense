<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Daily cluster snapshot at 23:55 (records cluster composition for longitudinal tracking)
Schedule::command('osca:snapshot-clusters')
    ->dailyAt('23:55')
    ->appendOutputTo(storage_path('logs/snapshot.log'));

// Daily GIS road-route backfill (05:00) — caches ORS road distances for the
// nearest facility per type. It resumes where it left off (skips fresh-cached
// pairs) and self-limits, so coverage completes over a few days within the ORS
// free-tier daily quota without manual re-runs. withoutOverlapping guards against
// a slow run still going when the next fires.
Schedule::command('gis:cache-route-distances --facilities=12')
    ->dailyAt('05:00')
    ->withoutOverlapping(120)
    ->appendOutputTo(storage_path('logs/gis-route-cache.log'));

// After the backfill, refresh accessibility scores so newly-cached seniors flip
// from straight-line to road-based distance (local, fast). Runs at 06:00 so it
// follows the 05:00 route backfill.
Schedule::command('gis:score-proximity')
    ->dailyAt('06:00')
    ->withoutOverlapping(30)
    ->appendOutputTo(storage_path('logs/gis-score.log'));
