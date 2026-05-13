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
