# GIS Analytics Page Improvements — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix GIS Analytics page performance, heatmap visual quality, facility coverage, and layer category clutter.

**Architecture:** Four independent areas on the same GIS page — PHP API caching, JS heatmap tuning, seeder expansion, and HTML/JS cleanup. Tasks are ordered by impact: PHP backend first, then JS visuals, then data, then cleanup.

**Tech Stack:** Laravel 11, Leaflet.js, custom canvas KDE heatmap engine, PHPUnit, Blade

---

## File Map

| File | Changes |
|---|---|
| `app/Http/Controllers/GisApiController.php` | Cache GeoJSON response (5-min TTL), O(1) boundary lookup map, optional `?barangay=` filter |
| `app/Http/Controllers/ReportController.php` | Bust `gis.seniors_geojson` cache on geocode dispatch, add `Cache` import |
| `resources/views/reports/gis.blade.php` | Debounce filters, heatmap gradient/blur/opacity, dropdown cleanup |
| `database/seeders/PagsanjanFacilitySeeder.php` | Expand from 13 → ~56 facilities covering all 16 barangays |
| `tests/Feature/GisApiCachingTest.php` | New: PHP tests for caching and cache-bust |
| `tests/Feature/FacilitySeederTest.php` | New: PHP test asserting all 16 barangays have ≥ 3 facilities |

---

## Task 1: GisApiController — cache GeoJSON + O(1) boundary lookup + barangay filter

**Files:**
- Modify: `app/Http/Controllers/GisApiController.php:20-139`
- Test: `tests/Feature/GisApiCachingTest.php` (new)

### Context

The `seniors()` method rebuilds the full GeoJSON feature collection on every page load. It also calls `barangayBoundaryFeature()` for each senior, which does an O(m) linear scan over boundary features. We wrap the entire build in `Cache::remember()` and replace the per-senior scan with a pre-built keyed map.

The `Cache` import is already in `GisApiController` (`use Illuminate\Support\Facades\Cache;`).

- [ ] **Step 1: Create the failing test**

Create `tests/Feature/GisApiCachingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GisApiCachingTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin@osca.local'],
            ['name' => 'OSCA Admin', 'password' => bcrypt('password')]
        );
        $this->admin->syncRoles(['admin']);
    }

    #[Test]
    public function seniors_geojson_is_stored_in_cache_after_first_request(): void
    {
        Cache::forget('gis.seniors_geojson');
        $this->assertFalse(Cache::has('gis.seniors_geojson'));

        $this->actingAs($this->admin)
            ->getJson('/api/gis/seniors')
            ->assertStatus(200)
            ->assertJsonStructure(['type', 'features']);

        $this->assertTrue(Cache::has('gis.seniors_geojson'));
    }

    #[Test]
    public function barangay_filter_stores_separate_cache_key(): void
    {
        $key = 'gis.seniors_geojson.' . md5('Sabang');
        Cache::forget($key);
        $this->assertFalse(Cache::has($key));

        $this->actingAs($this->admin)
            ->getJson('/api/gis/seniors?barangay=Sabang')
            ->assertStatus(200)
            ->assertJsonStructure(['type', 'features']);

        $this->assertTrue(Cache::has($key));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```powershell
php artisan test tests/Feature/GisApiCachingTest.php --stop-on-failure
```

Expected: both tests FAIL — `Cache::has('gis.seniors_geojson')` returns false because caching is not implemented yet.

- [ ] **Step 3: Implement caching + boundary map in `GisApiController::seniors()`**

Replace the entire `seniors()` method (lines 20–139) with:

```php
public function seniors(Request $request): JsonResponse
{
    $barangayFilter = $request->query('barangay');
    $cacheKey = ($barangayFilter && $barangayFilter !== 'all')
        ? 'gis.seniors_geojson.'.md5($barangayFilter)
        : 'gis.seniors_geojson';

    $payload = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($barangayFilter) {
        $query = SeniorCitizen::active()
            ->with(['latestMlResult', 'latestAccessibilityMetric'])
            ->orderBy('id');

        if ($barangayFilter && $barangayFilter !== 'all') {
            $query->where('barangay', $barangayFilter);
        }

        $seniors = $query->get([
            'id', 'osca_id', 'first_name', 'middle_name', 'last_name',
            'name_extension', 'barangay', 'date_of_birth', 'latitude',
            'longitude', 'location_source', 'location_accuracy',
        ]);

        $boundaryMap = collect($this->barangayBoundaryFeatures())
            ->keyBy(fn ($f) => $this->normalizeBarangayName(
                (string) ($f['properties']['name']
                    ?? $f['properties']['NAME']
                    ?? $f['properties']['barangay']
                    ?? $f['properties']['BARANGAY']
                    ?? $f['properties']['brgy_name']
                    ?? $f['properties']['BRGY_NAME']
                    ?? $f['properties']['ADM4_EN']
                    ?? $f['properties']['adm4_en']
                    ?? '')
            ));

        $groups = $this->groupSeniorsByBarangay($seniors);
        $features = [];
        $matchedSeniorCount = 0;

        foreach ($seniors as $senior) {
            $normalizedBarangay = $this->normalizeBarangayName((string) $senior->barangay);
            $boundaryFeature = $boundaryMap[$normalizedBarangay] ?? null;

            if (! $boundaryFeature) {
                continue;
            }

            $barangay = $this->boundaryFeatureName($boundaryFeature);
            $normalized = $this->normalizeBarangayName($barangay);
            $stats = $groups[$normalized] ?? $this->emptyBarangayStats($barangay);
            $coordinates = $this->coordinatesForSenior($senior);
            $point = [$coordinates[0], $coordinates[1]];
            $locationStatus = $coordinates[2];

            if (! is_finite($point[0]) || ! is_finite($point[1])
                || ($point[0] === 0.0 && $point[1] === 0.0)) {
                continue;
            }

            $latestResult = $senior->latestMlResult;
            $accessibilityMetric = $senior->latestAccessibilityMetric;
            $riskScore = $latestResult?->composite_risk ?? $latestResult?->rule_composite;
            $risk = $latestResult?->overall_risk_level
                ? ucfirst(strtolower($latestResult->overall_risk_level))
                : 'Unknown';
            $clusterId = $latestResult?->cluster_named_id ?? $latestResult?->cluster_id;
            $cluster = $latestResult?->cluster_named_id
                ? 'Group '.$latestResult->cluster_named_id
                : ($latestResult?->cluster_name ?: 'Unassigned');
            $accessibilityScore = $accessibilityMetric?->accessibility_score !== null
                ? (float) $accessibilityMetric->accessibility_score
                : null;
            $accessibilityScorePercent = $this->accessibilityScorePercent($accessibilityScore);

            $matchedSeniorCount++;

            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$point[1], $point[0]],
                ],
                'properties' => [
                    'anonymized_id' => $senior->osca_id ?: 'SEN-'.str_pad((string) $senior->id, 4, '0', STR_PAD_LEFT),
                    'age' => $senior->age,
                    'composite_risk' => $latestResult?->composite_risk,
                    'senior_id' => $senior->id,
                    'senior_name' => $senior->full_name,
                    'osca_id' => $senior->osca_id,
                    'barangay' => $barangay,
                    'senior_count' => 1,
                    'total_seniors' => $stats['count'],
                    'high_risk_count' => strtoupper($risk) === 'HIGH' ? 1 : 0,
                    'barangay_total_seniors' => $stats['count'],
                    'barangay_accessibility_status' => $this->accessibilityStatus($stats['accessibility_score_percent']),
                    'risk_score' => $riskScore !== null ? round((float) $riskScore, 4) : null,
                    'risk_level' => $risk,
                    'cluster_id' => $clusterId,
                    'cluster_label' => $cluster,
                    'cluster' => $cluster,
                    'health_group_id' => $clusterId,
                    'health_group' => $cluster,
                    'gis_proximity_score' => $accessibilityScorePercent,
                    'accessibility_score' => $accessibilityScore,
                    'accessibility_status' => $this->accessibilityStatus($accessibilityScorePercent),
                    'coordinate_mode' => $locationStatus,
                    'location_source' => $locationStatus === 'generalized'
                        ? 'generalized_barangay_point'
                        : ($senior->location_source ?: $locationStatus),
                    'location_accuracy' => $locationStatus === 'generalized'
                        ? 'barangay_level_generalized'
                        : ($senior->location_accuracy ?: 'stored_coordinate'),
                    'location_status' => $locationStatus,
                    'is_generalized_senior_point' => $locationStatus === 'generalized',
                ],
            ];
        }

        return [
            'features' => $features,
            'total' => $seniors->count(),
            'barangay_count' => count($groups),
            'matched_senior_count' => $matchedSeniorCount,
            'unmatched_senior_count' => max(0, $seniors->count() - $matchedSeniorCount),
        ];
    });

    return $this->geoJsonResponse(
        $payload['features'],
        'database',
        'Database-backed senior GIS records loaded as generalized barangay-level points.',
        [
            'placement' => 'generalized_senior_points_by_barangay',
            'total' => $payload['total'],
            'metadata' => [
                'barangay_count' => $payload['barangay_count'],
                'matched_senior_count' => $payload['matched_senior_count'],
                'unmatched_senior_count' => $payload['unmatched_senior_count'],
                'aggregation' => 'per_senior_generalized_by_barangay',
            ],
        ]
    );
}
```

- [ ] **Step 4: Run tests — expect pass**

```powershell
php artisan test tests/Feature/GisApiCachingTest.php
```

Expected: 2 PASS

- [ ] **Step 5: Run full suite to check for regressions**

```powershell
php artisan test
```

Expected: all existing tests pass.

- [ ] **Step 6: Commit**

```powershell
git add app/Http/Controllers/GisApiController.php tests/Feature/GisApiCachingTest.php
git commit -m "perf(gis): cache seniors GeoJSON 5-min + O(1) boundary lookup + barangay filter param"
```

---

## Task 2: ReportController — bust cache on geocode dispatch

**Files:**
- Modify: `app/Http/Controllers/ReportController.php:46-51`
- Test: `tests/Feature/GisApiCachingTest.php` (extend)

### Context

When `runGisGeocode()` dispatches the geocode job, coordinates in the database will change within minutes. The cached GeoJSON must be invalidated so the next page load rebuilds with updated coordinates.

`Cache` is NOT currently imported in `ReportController`. The geocode route is `reports.gis.geocode` (POST).

- [ ] **Step 1: Add the failing test**

Add this test to `tests/Feature/GisApiCachingTest.php`:

```php
#[Test]
public function geocode_dispatch_busts_seniors_geojson_cache(): void
{
    Cache::put('gis.seniors_geojson', ['dummy' => true], now()->addMinutes(5));
    $this->assertTrue(Cache::has('gis.seniors_geojson'));

    $this->actingAs($this->admin)
        ->post(route('reports.gis.geocode'))
        ->assertRedirect();

    $this->assertFalse(Cache::has('gis.seniors_geojson'));
}
```

- [ ] **Step 2: Run test to verify it fails**

```powershell
php artisan test tests/Feature/GisApiCachingTest.php --filter geocode_dispatch_busts_seniors_geojson_cache
```

Expected: FAIL — `Cache::has('gis.seniors_geojson')` is still true after dispatch.

- [ ] **Step 3: Add `Cache` import and bust call to `ReportController`**

Add the import after the existing `use` block (around line 15):

```php
use Illuminate\Support\Facades\Cache;
```

Update `runGisGeocode()` (lines 46–51):

```php
public function runGisGeocode()
{
    Artisan::queue('gis:geocode');
    Cache::forget('gis.seniors_geojson');

    return back()->with('success', 'Geocoding job queued. Coordinates will update within a few minutes — refresh the GIS map to see the results.');
}
```

- [ ] **Step 4: Run tests — expect pass**

```powershell
php artisan test tests/Feature/GisApiCachingTest.php
```

Expected: 3 PASS

- [ ] **Step 5: Commit**

```powershell
git add app/Http/Controllers/ReportController.php tests/Feature/GisApiCachingTest.php
git commit -m "perf(gis): bust seniors GeoJSON cache when geocode job is dispatched"
```

---

## Task 3: gis.blade.php — debounce filter redraws

**Files:**
- Modify: `resources/views/reports/gis.blade.php` (two spots)

### Context

The `change` event listener at line 5174 calls `refreshRenderedLayer()` immediately on every filter change. Rapid filter changes (e.g. tabbing through dropdowns) chain full layer teardowns. A 120 ms debounce coalesces them.

No automated test for JS. Verification is manual.

- [ ] **Step 1: Add `debounce` helper near the module-level variable block**

Find the line `const routeDistanceCache = new Map();` (line 337). Insert the `debounce` helper immediately after:

```js
    const routeDistanceCache = new Map();
    const warnedInvalidClusterValues = new Set();
    let latestRequestId = 0;
    // ... (existing lines) ...

    function debounce(fn, ms) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), ms);
        };
    }
```

Place it after the `warnedInvalidClusterValues` line and before the `latestRequestId` line, so within the constant block, not inside any function.

- [ ] **Step 2: Replace the change event listener (lines 5174–5178)**

Replace:

```js
    document.addEventListener('change', function (event) {
        if ([MODE_ID, BARANGAY_FILTER_ID, RISK_FILTER_ID, CLUSTER_FILTER_ID, CLUSTER_POINTS_TOGGLE_ID].includes(event.target?.id) || event.target?.matches?.(KDE_OVERLAY_SELECTOR)) {
            refreshRenderedLayer();
        }
    });
```

With:

```js
    const debouncedRefresh = debounce(() => refreshRenderedLayer(), 120);
    document.addEventListener('change', function (event) {
        if ([MODE_ID, BARANGAY_FILTER_ID, RISK_FILTER_ID, CLUSTER_FILTER_ID, CLUSTER_POINTS_TOGGLE_ID].includes(event.target?.id) || event.target?.matches?.(KDE_OVERLAY_SELECTOR)) {
            debouncedRefresh();
        }
    });
```

- [ ] **Step 3: Manual smoke test**

```powershell
php artisan serve
```

Open http://127.0.0.1:8000/reports/gis. Rapidly click through all four filter dropdowns. The map should redraw once after you stop changing, not after each individual change.

- [ ] **Step 4: Commit**

```powershell
git add resources/views/reports/gis.blade.php
git commit -m "perf(gis): debounce filter redraws to 120ms to reduce layer teardown churn"
```

---

## Task 4: gis.blade.php — heatmap visual quality

**Files:**
- Modify: `resources/views/reports/gis.blade.php` (4 spots)

### Context

Four changes, all in the JS constants/functions block:
1. `CLUSTER_HEATMAP_GRADIENT` (line 245): replace the 10-stop rainbow with a smooth 5-stop cool-to-warm ramp.
2. `heatmapPixelOptions` cluster branch (line 1354/1358): increase maxRadius cap `42 → 52`, blur ratio `0.52 → 0.72`.
3. `buildHeatmapLayer` options (line 2534): change `minOpacity: 0.22 → 0.30`.

No automated test. Verification is visual.

- [ ] **Step 1: Replace `CLUSTER_HEATMAP_GRADIENT` (lines 245–256)**

Replace:

```js
    const CLUSTER_HEATMAP_GRADIENT = {
        0.00: '#253494',
        0.10: '#2166ac',
        0.22: '#1d91c0',
        0.34: '#41b6c4',
        0.46: '#35d07f',
        0.58: '#a6e22e',
        0.70: '#fff238',
        0.80: '#fdae21',
        0.90: '#f46d43',
        1.00: '#d7191c',
    };
```

With:

```js
    const CLUSTER_HEATMAP_GRADIENT = {
        0.00: '#e8f4f8',
        0.25: '#74c2e8',
        0.50: '#f0e442',
        0.75: '#e67e22',
        1.00: '#c0392b',
    };
```

- [ ] **Step 2: Update cluster heatmap pixel options (lines 1351–1361)**

Replace:

```js
        if (mode === 'cluster-heatmap') {
            // Floor at 6px so the kernel never inflates beyond its geographic radius
            // when zoomed out, preventing false cluster color in empty areas.
            const radius = Math.round(Math.max(6, Math.min(42, rawRadius)));

            return {
                radius,
                blur: Math.round(Math.max(4, Math.min(24, radius * 0.52))),
                radius_meters: Math.round(meters),
            };
        }
```

With:

```js
        if (mode === 'cluster-heatmap') {
            const radius = Math.round(Math.max(6, Math.min(52, rawRadius)));

            return {
                radius,
                blur: Math.round(Math.max(4, Math.min(32, radius * 0.72))),
                radius_meters: Math.round(meters),
            };
        }
```

- [ ] **Step 3: Update `minOpacity` in `buildHeatmapLayer` (line 2534)**

Replace:

```js
                minOpacity: 0.22,
```

With:

```js
                minOpacity: 0.30,
```

- [ ] **Step 4: Manual smoke test**

```powershell
php artisan serve
```

Open http://127.0.0.1:8000/reports/gis. Select "Cluster / Health Groups Heatmap" from the visualization dropdown. The heatmap should show a smooth cool-to-warm (blue → yellow → red) gradient with softer edges. Compare to the previous sharp rainbow appearance.

- [ ] **Step 5: Commit**

```powershell
git add resources/views/reports/gis.blade.php
git commit -m "fix(gis): soften cluster heatmap gradient, increase blur and min opacity"
```

---

## Task 5: Facility seeder — expand to all 16 barangays

**Files:**
- Modify: `database/seeders/PagsanjanFacilitySeeder.php`
- Test: `tests/Feature/FacilitySeederTest.php` (new)

### Context

The seeder currently has 13 facilities covering only 6 of Pagsanjan's 16 barangays. The other 10 barangays show artificial "no access" in the Accessibility Heatmap. Add ~43 facilities to reach minimum coverage (≥ 3 per barangay: Barangay Hall, Health Center, Church). Add Community Store for higher-density barangays. All entries are `sample_prototype_approximate`.

The seeder uses `Facility::updateOrCreate(['name' => ...], ...)` so re-running is safe.

- [ ] **Step 1: Create the failing test**

Create `tests/Feature/FacilitySeederTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Facility;
use Database\Seeders\PagsanjanFacilitySeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FacilitySeederTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function all_16_pagsanjan_barangays_have_at_least_3_facilities(): void
    {
        $this->seed(PagsanjanFacilitySeeder::class);

        $barangays = [
            'Anibong', 'Biñan', 'Buboy', 'Cabanbanan', 'Calusiche', 'Dingin',
            'Lambac', 'Layugan', 'Magdapio', 'Maulawin', 'Pinagsanjan',
            'Barangay I (Poblacion)', 'Barangay II (Poblacion)',
            'Sabang', 'Sampaloc', 'San Isidro',
        ];

        foreach ($barangays as $barangay) {
            $count = Facility::where('barangay', $barangay)->count();
            $this->assertGreaterThanOrEqual(
                3,
                $count,
                "Barangay '{$barangay}' must have at least 3 facilities, found {$count}"
            );
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```powershell
php artisan test tests/Feature/FacilitySeederTest.php
```

Expected: FAIL — multiple barangays have 0 or 1 facilities.

- [ ] **Step 3: Expand the seeder**

In `database/seeders/PagsanjanFacilitySeeder.php`, add the following entries inside the `$facilities = [...]` array, immediately before the closing `];` on the last line of the array:

```php
            // --- Anibong ---
            ['name' => 'Barangay Hall - Anibong',       'type' => 'Barangay Hall', 'barangay' => 'Anibong',           'address' => 'Approximate barangay hall, Anibong, Pagsanjan',      'latitude' => 14.2782, 'longitude' => 121.4588, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Anibong Health Post',            'type' => 'Health Center', 'barangay' => 'Anibong',           'address' => 'Approximate health post, Anibong, Pagsanjan',        'latitude' => 14.2785, 'longitude' => 121.4588, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Anibong Community Chapel',       'type' => 'Church',        'barangay' => 'Anibong',           'address' => 'Approximate chapel, Anibong, Pagsanjan',             'latitude' => 14.2782, 'longitude' => 121.4591, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Biñan ---
            ['name' => 'Barangay Hall - Biñan',          'type' => 'Barangay Hall', 'barangay' => 'Biñan',             'address' => 'Approximate barangay hall, Biñan, Pagsanjan',        'latitude' => 14.2728, 'longitude' => 121.4468, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Biñan Health Post',               'type' => 'Health Center', 'barangay' => 'Biñan',             'address' => 'Approximate health post, Biñan, Pagsanjan',          'latitude' => 14.2731, 'longitude' => 121.4468, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Biñan Community Chapel',          'type' => 'Church',        'barangay' => 'Biñan',             'address' => 'Approximate chapel, Biñan, Pagsanjan',               'latitude' => 14.2728, 'longitude' => 121.4471, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Buboy ---
            ['name' => 'Barangay Hall - Buboy',          'type' => 'Barangay Hall', 'barangay' => 'Buboy',             'address' => 'Approximate barangay hall, Buboy, Pagsanjan',        'latitude' => 14.2742, 'longitude' => 121.4618, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Buboy Health Post',               'type' => 'Health Center', 'barangay' => 'Buboy',             'address' => 'Approximate health post, Buboy, Pagsanjan',          'latitude' => 14.2745, 'longitude' => 121.4618, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Buboy Community Chapel',          'type' => 'Church',        'barangay' => 'Buboy',             'address' => 'Approximate chapel, Buboy, Pagsanjan',               'latitude' => 14.2742, 'longitude' => 121.4621, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Cabanbanan ---
            ['name' => 'Barangay Hall - Cabanbanan',     'type' => 'Barangay Hall', 'barangay' => 'Cabanbanan',        'address' => 'Approximate barangay hall, Cabanbanan, Pagsanjan',   'latitude' => 14.2648, 'longitude' => 121.4528, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Cabanbanan Health Post',          'type' => 'Health Center', 'barangay' => 'Cabanbanan',        'address' => 'Approximate health post, Cabanbanan, Pagsanjan',     'latitude' => 14.2651, 'longitude' => 121.4528, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Cabanbanan Community Chapel',     'type' => 'Church',        'barangay' => 'Cabanbanan',        'address' => 'Approximate chapel, Cabanbanan, Pagsanjan',          'latitude' => 14.2648, 'longitude' => 121.4531, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Calusiche ---
            ['name' => 'Barangay Hall - Calusiche',      'type' => 'Barangay Hall', 'barangay' => 'Calusiche',         'address' => 'Approximate barangay hall, Calusiche, Pagsanjan',    'latitude' => 14.2694, 'longitude' => 121.4502, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Calusiche Health Post',           'type' => 'Health Center', 'barangay' => 'Calusiche',         'address' => 'Approximate health post, Calusiche, Pagsanjan',      'latitude' => 14.2697, 'longitude' => 121.4502, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Calusiche Community Chapel',      'type' => 'Church',        'barangay' => 'Calusiche',         'address' => 'Approximate chapel, Calusiche, Pagsanjan',           'latitude' => 14.2694, 'longitude' => 121.4505, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Dingin ---
            ['name' => 'Barangay Hall - Dingin',         'type' => 'Barangay Hall', 'barangay' => 'Dingin',            'address' => 'Approximate barangay hall, Dingin, Pagsanjan',       'latitude' => 14.2758, 'longitude' => 121.4544, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Dingin Health Post',              'type' => 'Health Center', 'barangay' => 'Dingin',            'address' => 'Approximate health post, Dingin, Pagsanjan',         'latitude' => 14.2761, 'longitude' => 121.4544, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Dingin Community Chapel',         'type' => 'Church',        'barangay' => 'Dingin',            'address' => 'Approximate chapel, Dingin, Pagsanjan',              'latitude' => 14.2758, 'longitude' => 121.4547, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Layugan ---
            ['name' => 'Barangay Hall - Layugan',        'type' => 'Barangay Hall', 'barangay' => 'Layugan',           'address' => 'Approximate barangay hall, Layugan, Pagsanjan',      'latitude' => 14.2638, 'longitude' => 121.4572, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Layugan Health Post',             'type' => 'Health Center', 'barangay' => 'Layugan',           'address' => 'Approximate health post, Layugan, Pagsanjan',        'latitude' => 14.2641, 'longitude' => 121.4572, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Layugan Community Chapel',        'type' => 'Church',        'barangay' => 'Layugan',           'address' => 'Approximate chapel, Layugan, Pagsanjan',             'latitude' => 14.2638, 'longitude' => 121.4575, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Magdapio ---
            ['name' => 'Barangay Hall - Magdapio',       'type' => 'Barangay Hall', 'barangay' => 'Magdapio',          'address' => 'Approximate barangay hall, Magdapio, Pagsanjan',     'latitude' => 14.2802, 'longitude' => 121.4556, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Magdapio Health Post',            'type' => 'Health Center', 'barangay' => 'Magdapio',          'address' => 'Approximate health post, Magdapio, Pagsanjan',       'latitude' => 14.2805, 'longitude' => 121.4556, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Magdapio Community Chapel',       'type' => 'Church',        'barangay' => 'Magdapio',          'address' => 'Approximate chapel, Magdapio, Pagsanjan',            'latitude' => 14.2802, 'longitude' => 121.4559, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Sampaloc ---
            ['name' => 'Barangay Hall - Sampaloc',       'type' => 'Barangay Hall', 'barangay' => 'Sampaloc',          'address' => 'Approximate barangay hall, Sampaloc, Pagsanjan',     'latitude' => 14.2764, 'longitude' => 121.4558, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Sampaloc Health Post',            'type' => 'Health Center', 'barangay' => 'Sampaloc',          'address' => 'Approximate health post, Sampaloc, Pagsanjan',       'latitude' => 14.2767, 'longitude' => 121.4558, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Sampaloc Community Chapel',       'type' => 'Church',        'barangay' => 'Sampaloc',          'address' => 'Approximate chapel, Sampaloc, Pagsanjan',            'latitude' => 14.2764, 'longitude' => 121.4561, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- San Isidro ---
            ['name' => 'Barangay Hall - San Isidro',     'type' => 'Barangay Hall', 'barangay' => 'San Isidro',        'address' => 'Approximate barangay hall, San Isidro, Pagsanjan',   'latitude' => 14.2668, 'longitude' => 121.4612, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'San Isidro Health Post',          'type' => 'Health Center', 'barangay' => 'San Isidro',        'address' => 'Approximate health post, San Isidro, Pagsanjan',     'latitude' => 14.2671, 'longitude' => 121.4612, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'San Isidro Community Chapel',     'type' => 'Church',        'barangay' => 'San Isidro',        'address' => 'Approximate chapel, San Isidro, Pagsanjan',          'latitude' => 14.2668, 'longitude' => 121.4615, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Fill gaps in existing barangays ---

            // Lambac (had Hall only)
            ['name' => 'Lambac Health Post',             'type' => 'Health Center', 'barangay' => 'Lambac',            'address' => 'Approximate health post, Lambac, Pagsanjan',         'latitude' => 14.2692, 'longitude' => 121.4592, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Lambac Community Church',         'type' => 'Church',        'barangay' => 'Lambac',            'address' => 'Approximate church, Lambac, Pagsanjan',              'latitude' => 14.2689, 'longitude' => 121.4595, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Lambac Community Store',          'type' => 'Community Store','barangay' => 'Lambac',           'address' => 'Approximate community store, Lambac, Pagsanjan',     'latitude' => 14.2686, 'longitude' => 121.4592, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // Maulawin (had Hall only)
            ['name' => 'Maulawin Health Post',           'type' => 'Health Center', 'barangay' => 'Maulawin',          'address' => 'Approximate health post, Maulawin, Pagsanjan',       'latitude' => 14.2740, 'longitude' => 121.4625, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Maulawin Community Church',       'type' => 'Church',        'barangay' => 'Maulawin',          'address' => 'Approximate church, Maulawin, Pagsanjan',            'latitude' => 14.2737, 'longitude' => 121.4628, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Maulawin Community Store',        'type' => 'Community Store','barangay' => 'Maulawin',         'address' => 'Approximate community store, Maulawin, Pagsanjan',   'latitude' => 14.2734, 'longitude' => 121.4625, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // Pinagsanjan (had Hall only)
            ['name' => 'Pinagsanjan Health Post',        'type' => 'Health Center', 'barangay' => 'Pinagsanjan',       'address' => 'Approximate health post, Pinagsanjan, Pagsanjan',    'latitude' => 14.2662, 'longitude' => 121.4513, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Pinagsanjan Community Church',    'type' => 'Church',        'barangay' => 'Pinagsanjan',       'address' => 'Approximate church, Pinagsanjan, Pagsanjan',         'latitude' => 14.2659, 'longitude' => 121.4516, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Pinagsanjan Community Store',     'type' => 'Community Store','barangay' => 'Pinagsanjan',      'address' => 'Approximate community store, Pinagsanjan, Pagsanjan','latitude' => 14.2656, 'longitude' => 121.4513, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // Sabang (had Hall + Church, needs Health Center + Store)
            ['name' => 'Sabang Health Post',             'type' => 'Health Center', 'barangay' => 'Sabang',            'address' => 'Approximate health post, Sabang, Pagsanjan',         'latitude' => 14.2753, 'longitude' => 121.4527, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Sabang Community Store',          'type' => 'Community Store','barangay' => 'Sabang',           'address' => 'Approximate community store, Sabang, Pagsanjan',     'latitude' => 14.2750, 'longitude' => 121.4524, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // Barangay I (Poblacion) — has many, add distinct Health Center (separate from Municipal Hall/RHU)
            ['name' => 'Barangay I Health Center',       'type' => 'Health Center', 'barangay' => 'Barangay I (Poblacion)', 'address' => 'Approximate barangay health center, Barangay I, Pagsanjan', 'latitude' => 14.2720, 'longitude' => 121.4554, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // Barangay II (Poblacion) — has RHU/Hospital/Pharmacy, add Church
            ['name' => 'Barangay II Community Church',   'type' => 'Church',        'barangay' => 'Barangay II (Poblacion)', 'address' => 'Approximate church, Barangay II, Pagsanjan',   'latitude' => 14.2706, 'longitude' => 121.4568, 'source' => 'sample_prototype_approximate', 'is_active' => true],
```

- [ ] **Step 4: Run test to verify it passes**

```powershell
php artisan test tests/Feature/FacilitySeederTest.php
```

Expected: 1 PASS — all 16 barangays have ≥ 3 facilities.

- [ ] **Step 5: Run full test suite**

```powershell
php artisan test
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```powershell
git add database/seeders/PagsanjanFacilitySeeder.php tests/Feature/FacilitySeederTest.php
git commit -m "feat(seeder): expand facility coverage to all 16 Pagsanjan barangays (~56 total)"
```

---

## Task 6: gis.blade.php — layer category cleanup

**Files:**
- Modify: `resources/views/reports/gis.blade.php` (multiple spots)

### Context

Remove `density-heatmap` and `risk-heatmap` modes entirely. Rename two options. Remove the now-dead `RISK_HEATMAP_GRADIENT` constant and its fallback in `heatmapGradient()`. Keep `RISK_DISTRIBUTION_RAMP` — it is still used by `buildRiskDistributionRasterLayer()` for `risk-indicator-heatmap`.

**Important:** `toggleGisLayer()` returns `true` for `risk-indicator-heatmap` and `cluster-heatmap`, routing them to dedicated render functions. The generic `buildHeatmapLayer` path (which uses `heatmapGradient`) is only reached by `accessibility-heatmap` after this cleanup. That mode has its own inline gradient inside `heatmapGradient`, so the `return RISK_HEATMAP_GRADIENT` fallback becomes dead code and is safe to remove.

- [ ] **Step 1: Update the visualization dropdown HTML (lines 106–114)**

Replace:

```html
                    <select id="gis-visualization-mode" class="form-select">
                        <option value="markers">Senior Distribution Points</option>
                        <option value="density-heatmap">Barangay-Level Senior Heatmap</option>
                        <option value="risk-heatmap">Generalized Barangay-Based Heatmap</option>
                        <option value="accessibility-heatmap">Senior Distribution and Accessibility Heatmap</option>
                        <option value="barangay-density">Barangay Density View</option>
                        <option value="risk-indicator-heatmap">Risk Indicator Distribution</option>
                        <option value="cluster-heatmap">Health Group Cluster Distribution</option>
                    </select>
```

With:

```html
                    <select id="gis-visualization-mode" class="form-select">
                        <option value="markers">Senior Distribution Points</option>
                        <option value="accessibility-heatmap">Accessibility Heatmap</option>
                        <option value="barangay-density">Barangay Density View</option>
                        <option value="risk-indicator-heatmap">Risk Indicator Distribution</option>
                        <option value="cluster-heatmap">Cluster / Health Groups Heatmap</option>
                    </select>
```

- [ ] **Step 2: Update `HEATMAP_MODES` set (lines 220–226)**

Replace:

```js
    const HEATMAP_MODES = new Set([
        'density-heatmap',
        'risk-heatmap',
        'accessibility-heatmap',
        'risk-indicator-heatmap',
        'cluster-heatmap',
    ]);
```

With:

```js
    const HEATMAP_MODES = new Set([
        'accessibility-heatmap',
        'risk-indicator-heatmap',
        'cluster-heatmap',
    ]);
```

- [ ] **Step 3: Remove `RISK_HEATMAP_GRADIENT` constant (lines 227–232)**

Delete the entire block:

```js
    const RISK_HEATMAP_GRADIENT = {
        0.12: '#22c55e',
        0.48: '#facc15',
        0.76: '#fb923c',
        1.00: '#ef4444',
    };
```

- [ ] **Step 4: Update `heatmapWeight()` — remove density-heatmap and risk-heatmap branches (lines 947–977)**

Replace:

```js
    function heatmapWeight(feature, mode) {
        const props = feature.properties || {};

        if (mode === 'density-heatmap') {
            return seniorCount(feature);
        }

        if (mode === 'risk-heatmap') {
            const count = seniorCount(feature);
            return count > 0 ? clampUnit((numericValue(props.high_risk_count) ?? 0) / count) : null;
        }

        if (mode === 'accessibility-heatmap') {
            return accessibilityNeedWeight(props);
        }

        if (mode === 'risk-indicator-heatmap') {
            const score = normalizedRiskScore(props.risk_score);
            if (score !== null) {
                return score;
            }

            return riskWeight(props.risk_level);
        }

        if (mode === 'cluster-heatmap') {
            return 1;
        }

        return null;
    }
```

With:

```js
    function heatmapWeight(feature, mode) {
        const props = feature.properties || {};

        if (mode === 'accessibility-heatmap') {
            return accessibilityNeedWeight(props);
        }

        if (mode === 'risk-indicator-heatmap') {
            const score = normalizedRiskScore(props.risk_score);
            if (score !== null) {
                return score;
            }

            return riskWeight(props.risk_level);
        }

        if (mode === 'cluster-heatmap') {
            return 1;
        }

        return null;
    }
```

- [ ] **Step 5: Update `heatmapLabels` — remove removed modes, update names (lines 616–622)**

Replace:

```js
        const heatmapLabels = {
            'density-heatmap': ['Barangay-Level Senior Heatmap', 'Low concentration', 'High concentration'],
            'risk-heatmap': ['Generalized Barangay-Based Heatmap', 'Low risk intensity', 'High risk intensity'],
            'accessibility-heatmap': ['Senior Distribution and Accessibility Heatmap', 'Better access', 'Greater access need'],
            'risk-indicator-heatmap': ['Risk Indicator Distribution', 'Lower risk indicator', 'Higher risk indicator'],
            'cluster-heatmap': ['Health Group Cluster Distribution', 'Assigned group color', 'Stronger local concentration'],
        };
```

With:

```js
        const heatmapLabels = {
            'accessibility-heatmap': ['Accessibility Heatmap', 'Better access', 'Greater access need'],
            'risk-indicator-heatmap': ['Risk Indicator Distribution', 'Lower risk indicator', 'Higher risk indicator'],
            'cluster-heatmap': ['Cluster / Health Groups Heatmap', 'Assigned group color', 'Stronger local concentration'],
        };
```

- [ ] **Step 6: Update `heatmapRadiusMeters()` — remove density-heatmap branch (lines 1322–1341)**

Replace:

```js
    function heatmapRadiusMeters(features, mode) {
        const spacingRadius = nearestNeighborDistanceMeters(features);
        const boundaryRadius = boundaryRadiusMeters();
        const fallbackRadius = mode === 'density-heatmap' ? 360 : (mode === 'cluster-heatmap' ? 300 : 260);
        const derivedRadius = median([spacingRadius ? spacingRadius * 1.35 : null, boundaryRadius, fallbackRadius]);

        if (mode === 'density-heatmap') {
            return Math.max(220, Math.min(720, derivedRadius ?? fallbackRadius));
        }

        if (mode === 'accessibility-heatmap') {
            return Math.max(180, Math.min(560, derivedRadius ?? fallbackRadius));
        }

        if (mode === 'cluster-heatmap') {
            return Math.max(300, Math.min(520, derivedRadius ?? fallbackRadius));
        }

        return Math.max(160, Math.min(480, derivedRadius ?? fallbackRadius));
    }
```

With:

```js
    function heatmapRadiusMeters(features, mode) {
        const spacingRadius = nearestNeighborDistanceMeters(features);
        const boundaryRadius = boundaryRadiusMeters();
        const fallbackRadius = mode === 'cluster-heatmap' ? 300 : 260;
        const derivedRadius = median([spacingRadius ? spacingRadius * 1.35 : null, boundaryRadius, fallbackRadius]);

        if (mode === 'accessibility-heatmap') {
            return Math.max(180, Math.min(560, derivedRadius ?? fallbackRadius));
        }

        if (mode === 'cluster-heatmap') {
            return Math.max(300, Math.min(520, derivedRadius ?? fallbackRadius));
        }

        return Math.max(160, Math.min(480, derivedRadius ?? fallbackRadius));
    }
```

- [ ] **Step 7: Update `heatmapMaxIntensity()` — remove density-heatmap branch (lines 1375–1381)**

Replace:

```js
    function heatmapMaxIntensity(points, mode) {
        if (mode === 'density-heatmap') {
            return Math.max(1, ...points.map((point) => Number(point[2]) || 0));
        }

        return 1;
    }
```

With:

```js
    function heatmapMaxIntensity(points, mode) {
        return 1;
    }
```

- [ ] **Step 8: Update `heatmapGradient()` — remove RISK_HEATMAP_GRADIENT fallback (lines 2454–2468)**

Replace:

```js
    function heatmapGradient(mode) {
        if (mode === 'cluster-heatmap') {
            return CLUSTER_HEATMAP_GRADIENT;
        }

        if (mode === 'accessibility-heatmap') {
            return {
                0.15: '#10b981',
                0.45: '#facc15',
                0.72: '#fb923c',
                1.00: '#ef4444',
            };
        }

        return RISK_HEATMAP_GRADIENT;
    }
```

With:

```js
    function heatmapGradient(mode) {
        if (mode === 'cluster-heatmap') {
            return CLUSTER_HEATMAP_GRADIENT;
        }

        return {
            0.15: '#10b981',
            0.45: '#facc15',
            0.72: '#fb923c',
            1.00: '#ef4444',
        };
    }
```

- [ ] **Step 9: Manual smoke test**

```powershell
php artisan serve
```

Open http://127.0.0.1:8000/reports/gis. Verify:
1. Visualization dropdown shows exactly 5 options: Senior Distribution Points, Accessibility Heatmap, Barangay Density View, Risk Indicator Distribution, Cluster / Health Groups Heatmap.
2. No console errors when switching between each mode.
3. KDE overlay checkboxes (Risk Distribution, Health Group / Cluster, Accessibility / Facility Proximity) still work.

- [ ] **Step 10: Commit**

```powershell
git add resources/views/reports/gis.blade.php
git commit -m "fix(gis): remove density/risk-heatmap modes, rename accessibility and cluster options"
```

---

## Task 7: Smoke test — full GIS page validation

No code changes. Verifies the combined result of all tasks.

- [ ] **Step 1: Run PHP test suite**

```powershell
php artisan test
```

Expected: all tests pass (including `GisApiCachingTest` and `FacilitySeederTest`).

- [ ] **Step 2: Start the application**

```powershell
.\start.bat
```

Wait 30–60 s for Python ML services to finish loading.

- [ ] **Step 3: Open GIS Analytics**

Navigate to http://127.0.0.1:8000/reports/gis. Verify:
- Page loads without PHP errors.
- KPI cards show mapped seniors count.
- Map renders barangay boundaries and senior markers.

- [ ] **Step 4: Test each visualization mode**

Switch through all 5 dropdown options. For each, verify:
- Map updates without JS errors in browser console.
- No blank/black heatmap tiles.
- Status bar at the bottom of the map shows a success message.

Specific checks:
- **Cluster / Health Groups Heatmap**: gradient is smooth cool-to-warm (blue → yellow → red), not the old rainbow.
- **Accessibility Heatmap**: renders normally, showing green (good access) to red (limited access).
- **Risk Indicator Distribution**: renders normally.

- [ ] **Step 5: Test KDE overlay checkboxes**

Select "Barangay Density View". Check all three KDE overlay boxes. Verify overlays appear on top of the barangay layer without errors.

- [ ] **Step 6: Test filter debounce**

Open browser DevTools → Network tab. Rapidly click through all filter dropdowns. Verify that GIS API calls do NOT fire on every single keypress — they should batch.

- [ ] **Step 7: Test facility count on GIS map**

Switch to "Senior Distribution Points". Click the Facilities toggle (if present) or verify facility markers appear. There should be significantly more than 13 pin icons spread across multiple barangays.

- [ ] **Step 8: Run the smoke script**

```powershell
.\.claude\skills\run-osca-system\smoke.ps1 -Password "Admin@OSCA2026!"
```

Expected: ALL PASS 14/14.
