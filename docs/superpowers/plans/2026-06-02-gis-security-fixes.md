# GIS Security & Correctness Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix 10 confirmed bugs from the PR #58 GIS module code review — covering unauthenticated API routes (PII exposure), unmasked coordinate export, config-cache breakage, scoring algorithm error, counter logic bugs, and committed development artifacts.

**Architecture:** Six self-contained tasks touching routes, one controller, two Artisan commands, one config file, and the git index. No new files are created except the config additions; no schema changes required. Tasks 1–5 are independent and can be executed in any order. Task 6 (artifact removal) is also independent.

**Tech Stack:** Laravel 11, PHP 8.3, Blade, PHPUnit (for any tests). Branch: `feature/gis-semi-final`.

---

## File Map

| File | What changes |
|---|---|
| `routes/api.php` | Remove all 5 GIS routes (moved to web.php) |
| `routes/web.php` | Add 5 GIS routes inside `auth` + `role` middleware group |
| `app/Http/Controllers/ReportController.php` | Round lat/lng to 3 dp in `exportGis()` CSV callback |
| `config/services.php` | Add 4 missing ORS keys: `api_key`, `ca_bundle`, `verify_ssl`, `snap_radius_meters` |
| `app/Console/Commands/CacheGisRouteDistances.php` | Replace 4 `env()` calls with `config()`; fix `--seniors=0` falsy check; fix rate-limit counter; add `Log::warning` for SSL-off; add `Log` import |
| `app/Console/Commands/ScoreGisProximity.php` | Fix normalization denominator in `scoreSenior()` |
| `.gitignore` | Add `storage/app/gis/` entry |
| `backup-before-codex-continue.patch` | `git rm` |
| `backup-current-codex-changes.patch` | `git rm` |
| `resources/views/reports/gis.blade.backup.php` | `git rm` |
| `storage/app/gis/geocode_status.json` | `git rm` |

---

## Task 1: Authenticate GIS API Routes (Finding #1 — Critical)

**Context:** All 5 `/api/gis/*` routes sit bare in `routes/api.php` which uses the `api` middleware group — stateless, no session. Any anonymous caller can enumerate OSCA IDs, ages, risk scores, and GPS coordinates. The fix moves the routes to `routes/web.php` (session-aware), inside the existing `auth` guard and the same `role:admin,encoder,viewer` guard used by the GIS report view. URL paths stay identical so no JS changes are needed.

**Files:**
- Modify: `routes/api.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Empty `routes/api.php`**

Replace the entire contents of `routes/api.php` with:

```php
<?php

use Illuminate\Support\Facades\Route;

// GIS data routes have been moved to routes/web.php (auth-protected, session-aware).
// See routes/web.php → Route::middleware(['auth']) → prefix('api/gis') group.
```

- [ ] **Step 2: Add GIS routes to `routes/web.php` inside the auth group**

Open `routes/web.php`. The existing auth group ends at the closing brace before `require __DIR__.'/auth.php'`. Add the GIS block **inside** `Route::middleware(['auth'])->group(...)`, after the `require __DIR__.'/users.php';` line:

```php
    // GIS data API — called via JS fetch from the GIS report view.
    // Must stay in the web (session) middleware group so browser auth works.
    Route::middleware('role:admin,encoder,viewer')
        ->prefix('api/gis')
        ->name('api.gis.')
        ->group(function () {
            use App\Http\Controllers\GisApiController;

            Route::get('/seniors', [GisApiController::class, 'seniors'])->name('seniors');
            Route::get('/facilities', [GisApiController::class, 'facilities'])->name('facilities');
            Route::get('/boundary/pagsanjan', [GisApiController::class, 'pagsanjanBoundary'])->name('boundary.pagsanjan');
            Route::get('/boundary/barangays', [GisApiController::class, 'barangayBoundaries'])->name('boundary.barangays');
            Route::get('/route-distance', [GisApiController::class, 'routeDistance'])->name('route-distance');
        });
```

**Note on `use` inside a closure:** Laravel route files are included via `require`, so `use` statements must be at the file's top level. Move the `use App\Http\Controllers\GisApiController;` line to the top of `routes/web.php` instead of inside the closure.

The final `routes/web.php` top-of-file imports section should include:
```php
use App\Http\Controllers\GisApiController;
```

And the route group (no `use` inside the closure):
```php
    Route::middleware('role:admin,encoder,viewer')
        ->prefix('api/gis')
        ->name('api.gis.')
        ->group(function () {
            Route::get('/seniors', [GisApiController::class, 'seniors'])->name('seniors');
            Route::get('/facilities', [GisApiController::class, 'facilities'])->name('facilities');
            Route::get('/boundary/pagsanjan', [GisApiController::class, 'pagsanjanBoundary'])->name('boundary.pagsanjan');
            Route::get('/boundary/barangays', [GisApiController::class, 'barangayBoundaries'])->name('boundary.barangays');
            Route::get('/route-distance', [GisApiController::class, 'routeDistance'])->name('route-distance');
        });
```

- [ ] **Step 3: Verify routes are registered**

```bash
php artisan route:list --path=api/gis
```

Expected output: 5 rows, each with middleware column showing `web, auth, role:admin,encoder,viewer`.

Previously they showed `api` with no auth middleware.

- [ ] **Step 4: Smoke-test anonymous access is blocked**

```bash
php artisan tinker --execute="echo file_get_contents('http://127.0.0.1:8000/api/gis/seniors');"
```

Expected: redirect to login (302) or 401/403. NOT a JSON response with senior data.

- [ ] **Step 5: Commit**

```bash
git add routes/api.php routes/web.php
git commit -m "fix(security): authenticate GIS API routes — move from stateless api group to auth+role web group"
```

---

## Task 2: Generalize Coordinates in GIS Export CSV (Finding #2 — High)

**Context:** `ReportController::exportGis()` writes `$senior->latitude` and `$senior->longitude` verbatim (up to 7 dp, ~1 cm precision) to a downloadable CSV. For seniors with a `manual_pin` or `gps_capture` location this is a household-level coordinate. Fix: round both values to 3 decimal places (~111 m grid) before writing to CSV. Precision sufficient for district-level accessibility analysis; insufficient to identify individual homes.

**Files:**
- Modify: `app/Http/Controllers/ReportController.php` (lines 505–506)

- [ ] **Step 1: Update the two coordinate cells in the `fputcsv` call**

In `exportGis()`, find the `fputcsv($file, [` data row (around line 502). Change lines 505 and 506:

Before:
```php
                        $senior->latitude,
                        $senior->longitude,
```

After:
```php
                        $senior->latitude !== null ? round((float) $senior->latitude, 3) : null,
                        $senior->longitude !== null ? round((float) $senior->longitude, 3) : null,
```

- [ ] **Step 2: Update the CSV column header to reflect generalization**

In the same method, find the `fputcsv($file, [` header row (around line 477). Change:

Before:
```php
                'Latitude',
                'Longitude',
```

After:
```php
                'Latitude (approx, 3dp)',
                'Longitude (approx, 3dp)',
```

- [ ] **Step 3: Verify the change compiles**

```bash
php artisan config:clear && php artisan route:cache
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/ReportController.php
git commit -m "fix(privacy): generalize GIS export coordinates to 3 decimal places (~111m precision)"
```

---

## Task 3: Migrate `env()` Calls to `config()` in CacheGisRouteDistances (Finding #3 — High)

**Context:** After `php artisan config:cache` (standard on every deployment), `env()` returns `null` for all keys. `CacheGisRouteDistances` has 4 direct `env()` calls: `OPENROUTESERVICE_API_KEY` (line 36), `OPENROUTESERVICE_CA_BUNDLE` (line 418), `OPENROUTESERVICE_VERIFY_SSL` (line 427), `OPENROUTESERVICE_SNAP_RADIUS_METERS` (line 438). None of these are in `config/services.php`. Fix: add them to config, then read via `config()`.

**Files:**
- Modify: `config/services.php` (lines 42–48)
- Modify: `app/Console/Commands/CacheGisRouteDistances.php` (lines 36, 418, 427, 438)

- [ ] **Step 1: Add the 4 missing keys to `config/services.php`**

Find the `'openrouteservice' => [` block (lines 42–48). Replace it with:

```php
    'openrouteservice' => [
        'api_key' => env('OPENROUTESERVICE_API_KEY'),
        'base_url' => env('OPENROUTESERVICE_BASE_URL', 'https://api.heigit.org/openrouteservice'),
        'ca_bundle' => env('OPENROUTESERVICE_CA_BUNDLE', ''),
        'verify_ssl' => env('OPENROUTESERVICE_VERIFY_SSL', true),
        'snap_radius_meters' => env('OPENROUTESERVICE_SNAP_RADIUS_METERS', -1),
        'connect_timeout' => env('OPENROUTESERVICE_CONNECT_TIMEOUT', 3),
        'timeout' => env('OPENROUTESERVICE_TIMEOUT', 5),
        'retry_times' => env('OPENROUTESERVICE_RETRY_TIMES', 0),
        'retry_sleep_ms' => env('OPENROUTESERVICE_RETRY_SLEEP_MS', 500),
    ],
```

- [ ] **Step 2: Replace `env('OPENROUTESERVICE_API_KEY')` on line 36**

Before:
```php
        $apiKey = env('OPENROUTESERVICE_API_KEY');
```

After:
```php
        $apiKey = config('services.openrouteservice.api_key');
```

- [ ] **Step 3: Replace `env('OPENROUTESERVICE_CA_BUNDLE', '')` on line 418**

Before:
```php
        $caBundle = trim((string) env('OPENROUTESERVICE_CA_BUNDLE', ''));
```

After:
```php
        $caBundle = trim((string) config('services.openrouteservice.ca_bundle', ''));
```

- [ ] **Step 4: Replace `env('OPENROUTESERVICE_VERIFY_SSL', true)` on line 427**

Before:
```php
        if (filter_var(env('OPENROUTESERVICE_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN) === false) {
```

After:
```php
        if (filter_var(config('services.openrouteservice.verify_ssl', true), FILTER_VALIDATE_BOOLEAN) === false) {
```

- [ ] **Step 5: Replace `env('OPENROUTESERVICE_SNAP_RADIUS_METERS', -1)` on line 438**

Before:
```php
        $configuredRadius = (int) env('OPENROUTESERVICE_SNAP_RADIUS_METERS', -1);
```

After:
```php
        $configuredRadius = (int) config('services.openrouteservice.snap_radius_meters', -1);
```

- [ ] **Step 6: Clear config cache and verify**

```bash
php artisan config:cache && php artisan config:show services.openrouteservice
```

Expected: all 9 keys shown including `api_key`, `ca_bundle`, `verify_ssl`, `snap_radius_meters`.

- [ ] **Step 7: Commit**

```bash
git add config/services.php app/Console/Commands/CacheGisRouteDistances.php
git commit -m "fix(config): migrate ORS env() calls to config() so they survive config:cache"
```

---

## Task 4: Fix ScoreGisProximity Score Normalization (Finding #4 — High)

**Context:** `scoreSenior()` at line 171–173 divides `$weightedTotal` by `$availableWeight` — the sum of weights for only the facility categories that found a nearby facility. When a category has no facilities in the DB (e.g., no pharmacies seeded), its weight is excluded from the denominator, inflating the score. A senior near 3 out of 5 facility types scores 1.0 if perfectly close, instead of 0.65. Fix: divide by the sum of all configured weights (always 1.0 given the current `CATEGORY_CONFIG`).

**Files:**
- Modify: `app/Console/Commands/ScoreGisProximity.php` (lines 151–176)

- [ ] **Step 1: Add `$totalWeight` calculation at the start of `scoreSenior()`**

Before (line 156–157):
```php
        $weightedTotal = 0.0;
        $availableWeight = 0.0;
```

After:
```php
        $totalWeight = (float) array_sum(array_column(self::CATEGORY_CONFIG, 'weight'));
        $weightedTotal = 0.0;
        $availableWeight = 0.0;
```

- [ ] **Step 2: Change the denominator in the final score line**

Before (lines 171–173):
```php
        $payload['accessibility_score'] = $availableWeight > 0
            ? round($weightedTotal / $availableWeight, 4)
            : null;
```

After:
```php
        $payload['accessibility_score'] = $availableWeight > 0
            ? round($weightedTotal / $totalWeight, 4)
            : null;
```

The `$availableWeight > 0` guard is kept: it returns `null` when **no** facility of any category was found in the DB (completely empty facilities table), which is a valid "no data" state distinct from a score of 0.0.

- [ ] **Step 3: Verify the logic with a dry run**

```bash
php artisan gis:score-proximity --dry-run
```

Expected: scores for seniors visible in console output. A senior near only some facility types should now score below 1.0 (previously would have scored 1.0 when perfectly close to all present types).

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/ScoreGisProximity.php
git commit -m "fix(gis): normalize accessibility score against total weight, not just available-category weight"
```

---

## Task 5: Fix Three Bugs in CacheGisRouteDistances (Findings #5, #6, #7)

**Context:** Three independent bugs in the same file, batched into one task since they touch close-by lines:

- **Finding #5 (rate-limit counter):** `isRateLimitOrApiError()` returns true for any HTTP 400–599, so permanent-failure 404s inflate `$rateLimitOrApiErrors`. The early-stop condition checks `isOpenRouteServiceLimitError()` (429-only) AND `$rateLimitOrApiErrors >= $stopAfterRateLimits` — meaning one prior 404 makes the guard fire on the very first 429. Fix: only increment the counter via `isOpenRouteServiceLimitError()`.
- **Finding #6 (SSL logging):** `openRouteServiceVerifyOption()` only calls `$this->warn()` when SSL is disabled — no `Log::` entry — so scheduled cron runs expose the API bearer token silently. Fix: add `Log::warning()`.
- **Finding #7 (`--seniors=0`):** `$this->option('seniors') ? (int)...` is falsy for `"0"`, treating `--seniors=0` as "no limit". Fix: use `!== null` check.

**Files:**
- Modify: `app/Console/Commands/CacheGisRouteDistances.php` (lines 48, 70, 182–192, 416–434)

- [ ] **Step 1: Add `Log` facade import**

At the top of `CacheGisRouteDistances.php`, find the existing `use` block and add:

```php
use Illuminate\Support\Facades\Log;
```

- [ ] **Step 2: Fix `--seniors=0` falsy check (line 48)**

Before:
```php
        $seniorLimit = $this->option('seniors') ? (int) $this->option('seniors') : null;
```

After:
```php
        $seniorLimit = $this->option('seniors') !== null ? (int) $this->option('seniors') : null;
```

- [ ] **Step 3: Fix `if ($seniorLimit)` guard (line 70)**

Before:
```php
        if ($seniorLimit) {
            $seniorFeatures = array_slice($seniorFeatures, 0, $seniorLimit);
        }
```

After:
```php
        if ($seniorLimit !== null) {
            $seniorFeatures = array_slice($seniorFeatures, 0, $seniorLimit);
        }
```

- [ ] **Step 4: Fix the rate-limit counter in the catch block (lines 182–192)**

Before:
```php
                } catch (\Throwable $exception) {
                    $failed++;
                    if ($this->isRateLimitOrApiError($exception)) {
                        $rateLimitOrApiErrors++;
                    }
                    if ($this->isOpenRouteServiceLimitError($exception)
                        && $stopAfterRateLimits > 0
                        && $rateLimitOrApiErrors >= $stopAfterRateLimits
                    ) {
                        $hitRequestCap = true;
                    }
```

After:
```php
                } catch (\Throwable $exception) {
                    $failed++;
                    if ($this->isOpenRouteServiceLimitError($exception)) {
                        $rateLimitOrApiErrors++;
                        if ($stopAfterRateLimits > 0 && $rateLimitOrApiErrors >= $stopAfterRateLimits) {
                            $hitRequestCap = true;
                        }
                    }
```

- [ ] **Step 5: Add `Log::warning()` in `openRouteServiceVerifyOption()` for SSL-off (line 428)**

Before:
```php
        if (filter_var(config('services.openrouteservice.verify_ssl', true), FILTER_VALIDATE_BOOLEAN) === false) {
            $this->warn('WARNING: OPENROUTESERVICE_VERIFY_SSL=false is enabled. Use this only for local development; production should use SSL verification or OPENROUTESERVICE_CA_BUNDLE.');

            return false;
        }
```

After:
```php
        if (filter_var(config('services.openrouteservice.verify_ssl', true), FILTER_VALIDATE_BOOLEAN) === false) {
            $this->warn('WARNING: OPENROUTESERVICE_VERIFY_SSL=false is enabled. Use this only for local development; production should use SSL verification or OPENROUTESERVICE_CA_BUNDLE.');
            Log::warning('gis:cache-route-distances: SSL certificate verification is disabled via OPENROUTESERVICE_VERIFY_SSL=false.');

            return false;
        }
```

- [ ] **Step 6: Verify the command starts cleanly**

```bash
php artisan gis:cache-route-distances --dry-run --seniors=0
```

Expected: command starts, reports 0 seniors to process, exits cleanly. Previously it would process all seniors.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/CacheGisRouteDistances.php
git commit -m "fix(gis): fix rate-limit counter inflation, --seniors=0 falsy check, add Log warning for SSL-off"
```

---

## Task 6: Remove Committed Development Artifacts (Findings #8–#10)

**Context:** Four files were committed that have no place in the repository:
- `backup-before-codex-continue.patch` and `backup-current-codex-changes.patch` — AI session scratch files in the repo root.
- `resources/views/reports/gis.blade.backup.php` — 5 000-line backup snapshot of the GIS view.
- `storage/app/gis/geocode_status.json` — runtime-generated JSON; no PII but exposes DB row counts and sets a precedent for committing runtime artifacts.

A `.gitignore` entry for `storage/app/gis/` prevents future runtime files from the same path being accidentally committed.

**Files:**
- Delete (git rm): `backup-before-codex-continue.patch`
- Delete (git rm): `backup-current-codex-changes.patch`
- Delete (git rm): `resources/views/reports/gis.blade.backup.php`
- Delete (git rm): `storage/app/gis/geocode_status.json`
- Modify: `.gitignore`

- [ ] **Step 1: Stage all four files for removal**

```bash
git rm backup-before-codex-continue.patch backup-current-codex-changes.patch
git rm resources/views/reports/gis.blade.backup.php
git rm storage/app/gis/geocode_status.json
```

Expected: each file removed from the working tree and staged for deletion.

- [ ] **Step 2: Add `storage/app/gis/` to `.gitignore`**

Open `.gitignore`. Find the `storage/` section (or the end of the file). Add:

```gitignore
# Runtime GIS artifacts (geocoding status, cached files)
/storage/app/gis/
```

- [ ] **Step 3: Verify the gitignore takes effect**

```bash
git status
```

Expected: `.gitignore` shows as modified. The 4 removed files show as `deleted`. No untracked files appear under `storage/app/gis/`.

- [ ] **Step 4: Commit**

```bash
git add .gitignore
git commit -m "chore: remove committed dev artifacts (patch files, blade backup, geocode status json) + gitignore storage/app/gis/"
```

---

## Self-Review

**Spec coverage:**
- Finding #1 (unauthenticated routes) → Task 1 ✓
- Finding #2 (raw export coordinates) → Task 2 ✓
- Finding #3 (env() / config:cache) → Task 3 ✓
- Finding #4 (score normalization) → Task 4 ✓
- Finding #5 (rate-limit counter) → Task 5, Step 4 ✓
- Finding #6 (SSL log silence) → Task 5, Step 5 ✓
- Finding #7 (--seniors=0 falsy) → Task 5, Steps 2–3 ✓
- Finding #8 (geocode_status.json) → Task 6 ✓
- Finding #9 (gis.blade.backup.php) → Task 6 ✓
- Finding #10 (backup patch files) → Task 6 ✓

**Placeholder scan:** None found — every step contains exact file paths, before/after code, and expected command output.

**Type consistency:** No new types introduced. All method references (`openRouteServiceVerifyOption`, `isOpenRouteServiceLimitError`, `scoreSenior`, `exportGis`) match the actual method names in the codebase as verified above.
