<?php

use App\Http\Controllers\Internal\CronTickController;
use Illuminate\Support\Facades\Route;

// GIS data routes have been moved to routes/web.php (auth-protected, session-aware).
// See routes/web.php → Route::middleware(['auth']) → prefix('api/gis') group.

// Phase E — internal cron trigger (drains the queue + runs due scheduled
// tasks; also doubles as the keep-alive ping). Stateless, no session/CSRF,
// guarded by a shared-secret header instead of auth. See docs/DEPLOYMENT.md.
Route::middleware(['verify.cron.token', 'no.time.limit'])
    ->post('/internal/cron-tick', CronTickController::class)
    ->name('internal.cron-tick');
