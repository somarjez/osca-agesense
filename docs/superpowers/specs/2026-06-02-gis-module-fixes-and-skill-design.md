# GIS Module Fixes + Skill Design

**Date:** 2026-06-02  
**Branch:** `fix/gis-module-security-reviewed`  
**Status:** Approved for implementation

---

## Goal

Fix 10 confirmed bugs from the holistic GIS module code review, and write a `gis-module` project skill that captures the architecture, data pipeline, and safe operating procedures for future Claude sessions.

---

## Part 1 — Code Fixes

### Architecture

Six independent tasks grouped by proximity in the codebase. All changes are on `fix/gis-module-security-reviewed`. No schema changes, no new files except possible extraction of a throttle middleware.

### Task 1 — `CacheGisRouteDistances.php` (3 fixes)

**Fix A — Dead `isRateLimitOrApiError()` / auth errors don't stop the run**

`isRateLimitOrApiError()` has zero callers after the security refactor. A 401/403 response (invalid/rotated API key) never increments `$rateLimitOrApiErrors`, so all `$maxRequests` ORS calls fire before the command stops. Fix: extend `isOpenRouteServiceLimitError()` to also return `true` for HTTP 401 and 403, so auth failures trigger the same early-stop guard as rate limits. Remove the now-unused `isRateLimitOrApiError()` method.

```php
// Before
private function isOpenRouteServiceLimitError(\Throwable $exception): bool
{
    return (int) $exception->getCode() === 429
        || str_contains(strtolower($exception->getMessage()), 'rate limit');
}

// After
private function isOpenRouteServiceLimitError(\Throwable $exception): bool
{
    $code = (int) $exception->getCode();
    return in_array($code, [429, 401, 403], true)
        || str_contains(strtolower($exception->getMessage()), 'rate limit')
        || str_contains(strtolower($exception->getMessage()), 'unauthorized')
        || str_contains(strtolower($exception->getMessage()), 'forbidden');
}
```

Also remove the dead `isRateLimitOrApiError()` method entirely (lines 477–484).

**Fix B — `RuntimeException` with no code never stored as permanent failure**

`isPermanentRouteFailure()` checks `in_array($code, [400, 404])` where `$code = (int) $exception->getCode()`. `RuntimeException` thrown without an explicit code returns `getCode() = 0`, which is not in `[400, 404]`, so `storeRouteFailure()` is never called. The same senior-facility pair retries forever. Fix: add code `0` as a permanent failure sentinel when the message indicates a structural routing failure.

```php
private function isPermanentRouteFailure(\Throwable $exception): bool
{
    $code = (int) $exception->getCode();
    $message = strtolower($exception->getMessage());

    if (in_array($code, [400, 404], true) && ! $this->isOpenRouteServiceLimitError($exception)) {
        return true;
    }

    // RuntimeExceptions thrown without an HTTP code (e.g. "no usable route") are also permanent
    if ($code === 0 && (
        str_contains($message, 'no usable route')
        || str_contains($message, 'returned no usable')
    )) {
        return true;
    }

    return false;
}
```

**Fix C — OSRM fallback sends no `User-Agent`**

`osrmRoute()` calls the public `router.project-osrm.org` demo server with no `User-Agent` header, violating OSRM's ToS and risking IP blocks when ORS is already down. Fix: add an identifying `User-Agent`.

```php
// Before
$response = Http::acceptJson()
    ->withOptions(['verify' => $verify])

// After
$response = Http::acceptJson()
    ->withHeaders(['User-Agent' => 'AgeSense-OSCA/1.0 (osca-agesense; contact: admin@osca.local)'])
    ->withOptions(['verify' => $verify])
```

---

### Task 2 — `GisApiController.php` (3 fixes)

**Fix A — `empty($id)` bypasses cache for id=0 and for absent IDs**

All four cache helpers (`cachedRouteDistance`, `cachedRouteFailure`, `storeRouteDistance`, `storeRouteFailure`) guard with `empty($id)`. In PHP, `empty(0)` is `true`, bypassing the cache for any record with ID 0. Fix: replace all `empty()` guards with `=== null`.

```php
// Before (in all 4 helpers)
if (empty($validated['senior_id']) || empty($validated['facility_id'])) {

// After
if ($validated['senior_id'] === null || $validated['facility_id'] === null) {
```

**Fix B — Dead `if (!$point)` guard; null-island [0,0] passes through**

`$point = [$coordinates[0], $coordinates[1]]` is a non-empty array and is always truthy. A senior whose fallback resolves to `[0.0, 0.0]` (no barangay match) appears on the map at null island. Fix: replace the dead guard with an explicit coordinate validity check.

```php
// Before
$point = [$coordinates[0], $coordinates[1]];
if (! $point) {
    continue;
}

// After
$point = [$coordinates[0], $coordinates[1]];
if (! is_finite($point[0]) || ! is_finite($point[1])
    || ($point[0] === 0.0 && $point[1] === 0.0)) {
    continue;
}
```

**Fix C — Barangay GeoJSON decoded from disk on every request**

`barangayBoundaryFeatures()` stores decoded GeoJSON in an instance property, which is reset on every request. The file is read and `json_decode`d on every call to `/api/gis/seniors`. Fix: wrap with `Cache::remember()`.

```php
// Before
private function barangayBoundaryFeatures(): array
{
    if ($this->barangayBoundaryFeatures !== null) {
        return $this->barangayBoundaryFeatures;
    }
    $path = storage_path('app/gis/boundaries/pagsanjan_barangays.geojson');
    // ...read and decode...
    $this->barangayBoundaryFeatures = $features;
    return $features;
}

// After
private function barangayBoundaryFeatures(): array
{
    if ($this->barangayBoundaryFeatures !== null) {
        return $this->barangayBoundaryFeatures;
    }
    $this->barangayBoundaryFeatures = Cache::remember(
        'gis.barangay_boundary_features',
        now()->addHours(24),
        function () {
            $path = storage_path('app/gis/boundaries/pagsanjan_barangays.geojson');
            // ...existing read/decode logic...
        }
    );
    return $this->barangayBoundaryFeatures;
}
```

Add `use Illuminate\Support\Facades\Cache;` to imports.

---

### Task 3 — `routes/web.php` (1 fix)

**Fix — Add throttle to `routeDistance` route**

The `/api/gis/route-distance` endpoint has no rate limiting. A viewer can exhaust the ORS free-tier daily quota (2000 req/day) in one map session. Fix: add `throttle:60,1` (60 requests per minute per user) to the `routeDistance` route specifically.

```php
// Before
Route::get('/route-distance', [GisApiController::class, 'routeDistance'])->name('route-distance');

// After
Route::get('/route-distance', [GisApiController::class, 'routeDistance'])
    ->middleware('throttle:60,1')
    ->name('route-distance');
```

---

### Task 4 — `ReportController.php` (1 fix)

**Fix — Replace synchronous `Artisan::call()` with queued dispatch**

`runGisGeocode()` calls `Artisan::call('gis:geocode')` synchronously, blocking the PHP-FPM worker for the full geocoding batch (30–120 seconds). Fix: dispatch via `Artisan::queue()` and return an immediate acknowledgement response.

```php
// Before
public function runGisGeocode(Request $request): RedirectResponse
{
    $exitCode = Artisan::call('gis:geocode');
    // ...
}

// After
public function runGisGeocode(Request $request): RedirectResponse
{
    Artisan::queue('gis:geocode');
    return back()->with('success', 'Geocoding job queued. Check back in a few minutes.');
}
```

Add `use Illuminate\Support\Facades\Artisan;` if not already imported (confirm before editing).

---

### Task 5 — `GeocodeSeniors.php` (1 fix)

**Fix — Remove duplicate `$query->limit()` at line 50**

`$query->limit($limit)` is applied at query-build time (line ~50) AND again before `->get()` (line ~125). The first application is dead for the `chunkById` path and misleading. Remove the early application; keep only the one immediately before `->get()`.

```php
// Remove this block (~line 50):
if ($limit) {
    $query->limit($limit);
}

// Keep the one at ~line 125:
if ($limit) {
    $query->limit($limit)->get(...);
}
```

---

## Part 2 — `gis-module` Skill

### File

`.claude/skills/gis-module/SKILL.md`

### Sections

1. **Overview** — what the GIS module does; 5 key files
2. **Data pipeline** — canonical run order with prerequisites
3. **Running commands** — all flags, defaults, safe values, expected output per command
4. **API endpoints** — what each returns, who can call it
5. **Key models and columns**
6. **Gotchas** — config:cache, ORS quota, OSRM ToS, boundary GeoJSON location

The skill is written so a fresh Claude session can safely operate the GIS pipeline, understand the data flow for development, and know which commands are safe to run without side effects (dry-run flags).

---

## Self-Review

**Placeholder scan:** No TBDs. All before/after code is complete.  
**Internal consistency:** Task 3 (throttle) and Task 2 Fix A (empty→null) are independent; the cache bypass fix and throttle fix address the ORS quota problem from two angles — both are needed.  
**Scope:** Focused — 5 tasks touching 5 files, 1 new skill file. Single plan.  
**Ambiguity:** Task 4 (`Artisan::queue`) assumes a queue worker is running (it is — `start.ps1` starts it). No ambiguity.
