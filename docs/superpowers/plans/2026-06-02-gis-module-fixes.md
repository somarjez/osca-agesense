# GIS Module Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix 6 confirmed bugs across 5 files from the holistic GIS module code review, then write the `gis-module` project skill.

**Architecture:** Six independent tasks: CacheGisRouteDistances (3 bugs), GisApiController (3 bugs), routes/web.php (throttle), ReportController (async geocode), GeocodeSeniors (duplicate limit), then skill authoring. All changes on branch `fix/gis-module-security-reviewed`.

**Tech Stack:** Laravel 11, PHP 8.3, Illuminate Cache, Illuminate Support Facades.

---

## File Map

| File | What changes |
|---|---|
| `app/Console/Commands/CacheGisRouteDistances.php` | Extend `isOpenRouteServiceLimitError` to cover 401/403; remove dead `isRateLimitOrApiError`; fix `isPermanentRouteFailure` for code=0; add `User-Agent` to OSRM call |
| `app/Http/Controllers/GisApiController.php` | Replace `empty()` with `=== null` in 4 helpers; fix dead `if (!$point)` guard; add `Cache::remember()` for barangay GeoJSON; add `Cache` import |
| `routes/web.php` | Add `throttle:60,1` to `routeDistance` route |
| `app/Http/Controllers/ReportController.php` | Replace `Artisan::call()` with `Artisan::queue()` in `runGisGeocode()` |
| `app/Console/Commands/GeocodeSeniors.php` | Remove duplicate `$query->limit($limit)` at line 50–52 |
| `.claude/skills/gis-module/SKILL.md` | New file — GIS module architecture + operations skill |

---

## Task 1: Fix Three Bugs in CacheGisRouteDistances

**Files:**
- Modify: `app/Console/Commands/CacheGisRouteDistances.php` (lines 477–498)

**Bug A:** `isRateLimitOrApiError()` is dead (zero callers). An invalid/rotated API key returns HTTP 401; `isOpenRouteServiceLimitError()` only checks 429, so `$rateLimitOrApiErrors` never increments and all `$maxRequests` ORS calls fire before stopping.

**Bug B:** `isPermanentRouteFailure()` checks `in_array($code, [400, 404])`. `RuntimeException` thrown without an explicit code has `getCode()=0`, which is not in that list — so "no usable route" failures are never stored, and the same pair retries every run.

**Bug C:** `osrmRoute()` sends no `User-Agent` header to `router.project-osrm.org` demo server, violating its ToS and risking IP blocks when ORS is already down.

- [ ] **Step 1: Replace `isOpenRouteServiceLimitError()` and remove dead `isRateLimitOrApiError()`**

In `app/Console/Commands/CacheGisRouteDistances.php`, find lines 477–490 and replace both methods with:

```php
    private function isOpenRouteServiceLimitError(\Throwable $exception): bool
    {
        $code = (int) $exception->getCode();
        $message = strtolower($exception->getMessage());

        return in_array($code, [429, 401, 403], true)
            || str_contains($message, 'rate limit')
            || str_contains($message, 'unauthorized')
            || str_contains($message, 'forbidden');
    }
```

The `isRateLimitOrApiError()` method (lines 477–484) is entirely removed.

- [ ] **Step 2: Fix `isPermanentRouteFailure()` for code=0**

Find lines 492–498 and replace:

```php
    private function isPermanentRouteFailure(\Throwable $exception): bool
    {
        $code = (int) $exception->getCode();
        $message = strtolower($exception->getMessage());

        if (in_array($code, [400, 404], true) && ! $this->isOpenRouteServiceLimitError($exception)) {
            return true;
        }

        // RuntimeExceptions without an HTTP code (e.g. "no usable route") are also permanent
        if ($code === 0 && (
            str_contains($message, 'no usable route')
            || str_contains($message, 'returned no usable')
        )) {
            return true;
        }

        return false;
    }
```

- [ ] **Step 3: Add `User-Agent` to the OSRM HTTP call**

Find the `osrmRoute()` method (around line 368–398). Find this line:

```php
        $response = Http::acceptJson()
            ->withOptions(['verify' => $verify])
```

Replace with:

```php
        $response = Http::acceptJson()
            ->withHeaders(['User-Agent' => 'AgeSense-OSCA/1.0 (osca-agesense)'])
            ->withOptions(['verify' => $verify])
```

- [ ] **Step 4: Verify no remaining `isRateLimitOrApiError` references**

```bash
grep -n "isRateLimitOrApiError" app/Console/Commands/CacheGisRouteDistances.php
```

Expected: no output (method is gone, no callers).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/CacheGisRouteDistances.php
git commit -m "fix(gis): extend limit-error detection to 401/403, fix code=0 permanent failures, add OSRM User-Agent"
```

---

## Task 2: Fix Three Bugs in GisApiController

**Files:**
- Modify: `app/Http/Controllers/GisApiController.php` (lines 1–14, 54–58, 300–385, 705–722)

**Bug A:** `empty($id)` is `true` for id=0 in PHP — silently bypasses all 4 cache helpers for any record with ID 0 AND for absent IDs.

**Bug B:** `if (! $point)` at line 57 is dead — a 2-element array is always truthy; null-island `[0.0, 0.0]` passes through to the map.

**Bug C:** `barangayBoundaryFeatures()` stores decoded GeoJSON in an instance property — reset to null on every request; file is read and `json_decode`d on every call.

- [ ] **Step 1: Add `Cache` import**

Find the `use` block at the top of `app/Http/Controllers/GisApiController.php` (lines 5–13). Add `Cache` after the existing facades:

```php
use Illuminate\Support\Facades\Cache;
```

The imports block should look like:

```php
use App\Models\Facility;
use App\Models\SeniorCitizen;
use App\Models\SeniorFacilityRouteDistance;
use App\Models\SeniorFacilityRouteFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
```

- [ ] **Step 2: Fix `empty()` → `=== null` in all 4 cache helpers**

There are 4 occurrences of `empty($validated['senior_id']) || empty($validated['facility_id'])` at approximately lines 302, 327, 354, 383. Replace all 4 with `=== null`:

**`cachedRouteFailure()` (~line 302):**
```php
        if ($validated['senior_id'] === null || $validated['facility_id'] === null) {
            return null;
        }
```

**`cachedRouteDistance()` (~line 327):**
```php
        if ($validated['senior_id'] === null || $validated['facility_id'] === null) {
            return null;
        }
```

**`storeRouteDistance()` (~line 354):**
```php
        if ($validated['senior_id'] === null || $validated['facility_id'] === null) {
            return;
        }
```

**`storeRouteFailure()` (~line 383):**
```php
        if ($validated['senior_id'] === null || $validated['facility_id'] === null) {
            return;
        }
```

- [ ] **Step 3: Fix dead `if (!$point)` guard (~line 57)**

Find:
```php
            $point = [$coordinates[0], $coordinates[1]];
            $locationStatus = $coordinates[2];

            if (! $point) {
                continue;
            }
```

Replace with:
```php
            $point = [$coordinates[0], $coordinates[1]];
            $locationStatus = $coordinates[2];

            if (! is_finite($point[0]) || ! is_finite($point[1])
                || ($point[0] === 0.0 && $point[1] === 0.0)) {
                continue;
            }
```

- [ ] **Step 4: Wrap `barangayBoundaryFeatures()` with `Cache::remember()`**

Find lines 705–722 (the full `barangayBoundaryFeatures()` method):

```php
    private function barangayBoundaryFeatures(): array
    {
        if ($this->barangayBoundaryFeatures !== null) {
            return $this->barangayBoundaryFeatures;
        }

        $path = 'gis/boundaries/pagsanjan_barangays.geojson';
        if (! Storage::disk('local')->exists($path)) {
            return $this->barangayBoundaryFeatures = [];
        }

        $decoded = json_decode(Storage::disk('local')->get($path), true);
        if (! is_array($decoded) || ! isset($decoded['features']) || ! is_array($decoded['features'])) {
            return $this->barangayBoundaryFeatures = [];
        }

        return $this->barangayBoundaryFeatures = $decoded['features'];
    }
```

Replace with:

```php
    private function barangayBoundaryFeatures(): array
    {
        if ($this->barangayBoundaryFeatures !== null) {
            return $this->barangayBoundaryFeatures;
        }

        $this->barangayBoundaryFeatures = Cache::remember(
            'gis.barangay_boundary_features',
            now()->addHours(24),
            function () {
                $path = 'gis/boundaries/pagsanjan_barangays.geojson';
                if (! Storage::disk('local')->exists($path)) {
                    return [];
                }

                $decoded = json_decode(Storage::disk('local')->get($path), true);
                if (! is_array($decoded) || ! isset($decoded['features']) || ! is_array($decoded['features'])) {
                    return [];
                }

                return $decoded['features'];
            }
        );

        return $this->barangayBoundaryFeatures;
    }
```

- [ ] **Step 5: Verify syntax**

```bash
php artisan config:clear && php artisan route:cache
```

Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/GisApiController.php
git commit -m "fix(gis): replace empty() with null checks, fix dead point guard, cache barangay GeoJSON"
```

---

## Task 3: Add Throttle to routeDistance Route

**Files:**
- Modify: `routes/web.php` (line 43)

Without rate limiting, an authenticated viewer can exhaust the ORS free-tier daily quota (2000 req/day) in one map session.

- [ ] **Step 1: Add throttle middleware to `routeDistance`**

Find line 43 in `routes/web.php`:

```php
            Route::get('/route-distance', [GisApiController::class, 'routeDistance'])->name('route-distance');
```

Replace with:

```php
            Route::get('/route-distance', [GisApiController::class, 'routeDistance'])
                ->middleware('throttle:60,1')
                ->name('route-distance');
```

`throttle:60,1` = 60 requests per minute per authenticated user.

- [ ] **Step 2: Verify route list**

```bash
php artisan route:list --path=api/gis/route-distance
```

Expected: the route row shows `throttle:60,1` in its middleware column alongside `web,auth,role:*`.

- [ ] **Step 3: Commit**

```bash
git add routes/web.php
git commit -m "fix(gis): throttle routeDistance to 60 req/min per user to protect ORS quota"
```

---

## Task 4: Make GIS Geocode Async

**Files:**
- Modify: `app/Http/Controllers/ReportController.php` (lines 46–55)

`Artisan::call('gis:geocode')` blocks the PHP-FPM worker for 30–120 seconds. The queue worker is already running (started by `start.ps1`).

- [ ] **Step 1: Replace `Artisan::call()` with `Artisan::queue()`**

Find the `runGisGeocode()` method (lines 46–55):

```php
    public function runGisGeocode()
    {
        $exitCode = Artisan::call('gis:geocode');

        if ($exitCode !== 0) {
            return back()->with('error', 'Bulk geocode failed. Check the command output in logs or run php artisan gis:geocode manually.');
        }

        return back()->with('success', 'Bulk geocode completed. Senior map coordinates remain barangay-level approximations only.');
    }
```

Replace with:

```php
    public function runGisGeocode()
    {
        Artisan::queue('gis:geocode');

        return back()->with('success', 'Geocoding job queued. Coordinates will update within a few minutes — refresh the GIS map to see the results.');
    }
```

- [ ] **Step 2: Verify syntax**

```bash
php artisan route:cache
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/ReportController.php
git commit -m "fix(gis): queue gis:geocode instead of blocking HTTP worker with Artisan::call"
```

---

## Task 5: Remove Duplicate limit() in GeocodeSeniors

**Files:**
- Modify: `app/Console/Commands/GeocodeSeniors.php` (lines 50–52)

`$query->limit($limit)` is applied at query-build time (line 50) AND again before `->get()` (line 126). The early application is dead for the `chunkById` path and misleading for future readers.

- [ ] **Step 1: Remove the early `limit()` block**

Find lines 50–52:

```php
        if ($limit) {
            $query->limit($limit);
        }
```

Delete these 3 lines entirely. The `limit()` call that remains at line 125 (`$processSeniors($query->limit($limit)->get())`) is the authoritative one.

- [ ] **Step 2: Verify**

```bash
php artisan gis:geocode --dry-run --limit=5
```

Expected: processes exactly 5 seniors and exits cleanly. No PHP errors.

- [ ] **Step 3: Commit**

```bash
git add app/Console/Commands/GeocodeSeniors.php
git commit -m "fix(gis): remove duplicate query limit() applied before chunkById path"
```

---

## Task 6: Write the `gis-module` Project Skill

**Files:**
- Create: `.claude/skills/gis-module/SKILL.md`

This skill is loaded by future Claude sessions when working on anything GIS-related. It covers architecture, safe command usage, API endpoints, and gotchas.

- [ ] **Step 1: Read the existing skill format for reference**

Read `.claude/skills/run-osca-system/SKILL.md` to match the style/format. (Already done — the format uses a flat SKILL.md with headers and tables.)

- [ ] **Step 2: Create the skill file**

Create `.claude/skills/gis-module/SKILL.md` with this exact content:

```markdown
---
name: gis-module
description: Use when working on anything GIS-related in the AgeSense OSCA system — understanding the module architecture, running the GIS artisan commands, debugging map issues, or modifying the GIS report. Covers data pipeline, safe command flags, API endpoints, and known gotchas.
---

# GIS Module — AgeSense OSCA

The GIS module gives OSCA staff an interactive Pagsanjan map showing senior distribution, risk heatmaps, facility accessibility, and route-distance context. It is privacy-safe: individual senior points are generalized to barangay level unless a field-verified GPS pin exists.

---

## Key Files

| File | Role |
|---|---|
| `app/Http/Controllers/GisApiController.php` | Serves all 5 `/api/gis/*` JSON endpoints |
| `app/Console/Commands/GeocodeSeniors.php` | Step 1 — assign barangay-level coordinates to seniors |
| `app/Console/Commands/ScoreGisProximity.php` | Step 2 — calculate accessibility scores from coordinates to nearby facilities |
| `app/Console/Commands/CacheGisRouteDistances.php` | Step 3 — precompute ORS road-network route distances for senior popups |
| `resources/views/reports/gis.blade.php` | The Leaflet map UI (~4800 lines, inline JS) |
| `app/Livewire/Surveys/ProfileSurvey.php` | GPS pin capture and manual location in the senior profile survey |
| `storage/app/gis/boundaries/` | GeoJSON boundary files (barangays + municipal boundary) |
| `storage/app/certs/cacert.pem` | Optional CA bundle for ORS SSL — only used if `OPENROUTESERVICE_CA_BUNDLE` is set |

---

## Data Pipeline (run in this order)

```
1. gis:geocode          → assigns lat/lng to seniors (barangay centroid)
2. gis:score-proximity  → writes SeniorAccessibilityMetric rows
3. gis:cache-route-distances → writes SeniorFacilityRouteDistance rows (requires ORS API key)
```

Each step is idempotent. Steps 1 and 2 are safe to re-run at any time. Step 3 calls an external API and burns quota — use `--dry-run` first.

---

## Running Commands Safely

### Step 1 — `gis:geocode`

Assigns barangay-level geocoordinates to all active seniors without GPS pins. Uses the local GeoJSON boundaries — no external API calls.

```bash
# Safe first run (preview only)
php artisan gis:geocode --dry-run

# Run for real
php artisan gis:geocode

# Re-geocode all (overwrite existing barangay-level coordinates, keep verified pins)
php artisan gis:geocode --force

# Single barangay
php artisan gis:geocode --barangay="Poblacion 1"

# Limit rows for testing
php artisan gis:geocode --dry-run --limit=10
```

Expected output columns: `Total seniors checked`, `Seniors updated`, `Skipped because already verified`, `Skipped because existing coordinates are present`.

Verify success:
```bash
php artisan gis:geocode --dry-run
# "Seniors that would be updated" should be 0 after a successful run
```

---

### Step 2 — `gis:score-proximity`

Calculates accessibility scores (0.0–1.0) from each geocoded senior to the 5 nearest facility categories. Writes to `senior_accessibility_metrics`. No external API calls.

```bash
# Preview scores without saving
php artisan gis:score-proximity --dry-run

# Score all seniors
php artisan gis:score-proximity

# Single senior (by DB id)
php artisan gis:score-proximity --senior-id=42

# Single barangay
php artisan gis:score-proximity --barangay="Barangay I (Pob.)"
```

Score interpretation: 1.0 = perfectly close to all facility types; 0.0 = at or beyond the distance cap for all. `null` = no facilities in DB (run `php artisan db:seed --class=PagsanjanFacilitySeeder` first).

---

### Step 3 — `gis:cache-route-distances`

Calls OpenRouteService (ORS) API to precompute road-network distances for senior popup accessibility panels. **Requires `OPENROUTESERVICE_API_KEY` in `.env`** (and `php artisan config:clear` after adding it).

```bash
# Always dry-run first to see how many ORS requests will be needed
php artisan gis:cache-route-distances --dry-run

# Limited run (test with 5 seniors)
php artisan gis:cache-route-distances --seniors=5 --sleep-ms=1000

# Production run (default: 1500 max requests, 2500ms sleep)
php artisan gis:cache-route-distances

# Re-cache everything (overwrites cached pairs)
php artisan gis:cache-route-distances --force
```

Key flags:
| Flag | Default | Meaning |
|---|---|---|
| `--seniors=N` | all | Max seniors to process |
| `--facilities=N` | 5 | Nearby facilities per senior |
| `--max-requests=N` | 1500 | ORS request cap for this run |
| `--stop-after-rate-limits=N` | 1 | Stop after N rate-limit (429) or auth (401/403) errors |
| `--sleep-ms=N` | 2500 | Delay between ORS requests (ms) |
| `--dry-run` | false | Count pairs without calling ORS |
| `--force` | false | Recalculate existing cached pairs |

ORS free tier: **2000 requests/day**. Check remaining quota before large runs.

---

## GIS API Endpoints

All 5 endpoints require authentication (`auth` + `role:admin,encoder,viewer`). They are served from `routes/web.php` (NOT `routes/api.php`) so session auth works from the browser.

| Endpoint | Returns | Notes |
|---|---|---|
| `GET /api/gis/seniors` | GeoJSON FeatureCollection — one Feature per active senior with coordinates | Includes risk, cluster, accessibility score; coordinates are barangay-level unless manual pin |
| `GET /api/gis/facilities` | GeoJSON FeatureCollection — all active facilities | Typed: health center, hospital, pharmacy, market, barangay hall |
| `GET /api/gis/boundary/pagsanjan` | GeoJSON Polygon — municipal boundary | Loaded from `storage/app/gis/boundaries/pagsanjan_boundary.geojson` |
| `GET /api/gis/boundary/barangays` | GeoJSON FeatureCollection — 16 barangay polygons | Loaded from `storage/app/gis/boundaries/pagsanjan_barangays.geojson`; cached 24h |
| `GET /api/gis/route-distance` | JSON — road distance + duration in metres/seconds | Throttled: 60 req/min/user; results cached in `senior_facility_route_distances` |

`routeDistance` query parameters:
- `origin_lat`, `origin_lng`, `destination_lat`, `destination_lng` — required floats
- `senior_id`, `facility_id` — optional ints; when provided, results are cached to DB

---

## Key Models

| Model | Table | Key columns |
|---|---|---|
| `SeniorAccessibilityMetric` | `senior_accessibility_metrics` | `senior_citizen_id`, `accessibility_score` (0.0–1.0), `distance_to_*_m`, `nearest_*_id` |
| `SeniorFacilityRouteDistance` | `senior_facility_route_distances` | `senior_citizen_id`, `facility_id`, `route_distance_m`, `route_duration_s`, `provider` |
| `SeniorFacilityRouteFailure` | `senior_facility_route_failures` | `senior_citizen_id`, `facility_id`, `status_code`, `error_message` — permanent failures (no road route exists) |
| `Facility` | `facilities` | `name`, `type`, `latitude`, `longitude`, `is_active` — seeded via `PagsanjanFacilitySeeder` |

---

## Gotchas

**`config:cache` breaks ORS key**  
After adding/changing `OPENROUTESERVICE_API_KEY` in `.env`, always run `php artisan config:clear` (or `php artisan config:cache`) to pick up the new value. Using `env()` directly in PHP code does not work after config caching.

**ORS free-tier quota**  
The free tier allows ~2000 requests/day. One full run for all 283 seniors × 5 facilities = up to 1415 requests. Use `--dry-run` to count before running. Set `--max-requests=200` for partial runs.

**OSRM fallback is a demo server**  
When ORS is unreachable, the command falls back to `router.project-osrm.org`. This is a public demo server — do not use it for production bulk runs. It has been known to rate-limit IPs.

**Barangay GeoJSON location**  
Boundary files live in `storage/app/gis/boundaries/` (gittracked — they are static data, not runtime artifacts). The `storage/app/gis/` path is gitignored for runtime files only.

**GeoJSON cached for 24 hours**  
`/api/gis/boundary/barangays` results are cached via `Cache::remember('gis.barangay_boundary_features', 24h)`. If you update the GeoJSON file, run `php artisan cache:clear` to invalidate.

**Manual GPS pins are preserved**  
`gis:geocode --force` overwrites barangay-level coordinates but never overwrites `location_source = 'manual_pin'` or `'gps_capture'`. Those are protected by `isVerifiedCoordinate()` check in the command.

**Smoke test for GIS API**  
The existing `smoke.ps1` already checks `GET /api/gis/seniors`. Run it to verify the module is working end-to-end:
```powershell
.\.claude\skills\run-osca-system\smoke.ps1 -Password "Admin@OSCA2026!"
```
```

- [ ] **Step 3: Commit the skill**

```bash
git add .claude/skills/gis-module/SKILL.md
git commit -m "feat(skills): add gis-module skill — architecture, pipeline, commands, gotchas"
```

---

## Self-Review

**Spec coverage:**
- CacheGisRouteDistances 3 fixes → Task 1 ✓
- GisApiController 3 fixes → Task 2 ✓
- routeDistance throttle → Task 3 ✓
- Async geocode → Task 4 ✓
- Duplicate limit → Task 5 ✓
- gis-module skill → Task 6 ✓

**Placeholder scan:** None — every step has exact before/after code or exact commands with expected output.

**Type consistency:** All method names match the actual file (`isOpenRouteServiceLimitError`, `isPermanentRouteFailure`, `barangayBoundaryFeatures`, `runGisGeocode`). All line-number references verified against the checked-out files.
