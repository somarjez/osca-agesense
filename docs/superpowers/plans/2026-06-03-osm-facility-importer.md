# OSM Facility Importer — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `php artisan facilities:import-osm` — a command that fetches real facility data from OpenStreetMap's Overpass API and upserts it into the `facilities` table, deactivating superseded approximate records.

**Architecture:** Single artisan command with three concerns: (1) Overpass HTTP query with retry, (2) tag-to-type mapping + barangay assignment via point-in-polygon against the existing GeoJSON boundary file, (3) upsert by `osm_id` with optional supersession of approximate records. A new `osm_id VARCHAR(30)` migration provides the deduplication key.

**Tech Stack:** Laravel 11, Laravel Http facade (`Http::fake()` for tests), PHPUnit, `storage/app/gis/boundaries/pagsanjan_barangays.geojson`

---

## File Map

| File | Purpose |
|---|---|
| `database/migrations/2026_06_03_000001_add_osm_id_to_facilities_table.php` | Add nullable unique `osm_id` column |
| `app/Console/Commands/ImportOsmFacilities.php` | Full artisan command |
| `tests/Feature/ImportOsmFacilitiesTest.php` | Feature tests with mocked HTTP |

---

## Task 1: Migration — add `osm_id` to facilities table

**Files:**
- Create: `database/migrations/2026_06_03_000001_add_osm_id_to_facilities_table.php`
- Test: `tests/Feature/ImportOsmFacilitiesTest.php` (new)

### Context

The `facilities` table needs a `osm_id VARCHAR(30) NULL` column with a unique index to deduplicate imports across re-runs. Values look like `node:12345678` or `way:98765432`.

The `Facility` model's `$fillable` array is at `app/Models/Facility.php:13` and must have `osm_id` added.

- [ ] **Step 1: Create the test file with a schema assertion**

Create `tests/Feature/ImportOsmFacilitiesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ImportOsmFacilitiesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function overpassResponse(array $elements): array
    {
        return ['version' => 0.6, 'elements' => $elements];
    }

    private function osmNode(int $id, float $lat, float $lon, array $tags): array
    {
        return ['type' => 'node', 'id' => $id, 'lat' => $lat, 'lon' => $lon, 'tags' => $tags];
    }

    private function osmWay(int $id, float $lat, float $lon, array $tags): array
    {
        return ['type' => 'way', 'id' => $id, 'center' => ['lat' => $lat, 'lon' => $lon], 'tags' => $tags];
    }

    #[Test]
    public function facilities_table_has_osm_id_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('facilities', 'osm_id'),
            'facilities table must have osm_id column'
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```powershell
php artisan test tests/Feature/ImportOsmFacilitiesTest.php --filter facilities_table_has_osm_id_column
```

Expected: FAIL — `osm_id` column does not exist yet.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_06_03_000001_add_osm_id_to_facilities_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->string('osm_id', 30)->nullable()->unique()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->dropUnique(['osm_id']);
            $table->dropColumn('osm_id');
        });
    }
};
```

- [ ] **Step 4: Add `osm_id` to `Facility::$fillable`**

In `app/Models/Facility.php`, add `'osm_id'` to the `$fillable` array:

```php
protected $fillable = [
    'name',
    'type',
    'barangay',
    'address',
    'latitude',
    'longitude',
    'source',
    'is_active',
    'osm_id',
];
```

- [ ] **Step 5: Run migration**

```powershell
php artisan migrate
```

Expected: `Migrating: 2026_06_03_000001_add_osm_id_to_facilities_table` then `Migrated`.

- [ ] **Step 6: Run test — expect pass**

```powershell
php artisan test tests/Feature/ImportOsmFacilitiesTest.php --filter facilities_table_has_osm_id_column
```

Expected: 1 PASS

- [ ] **Step 7: Commit**

```powershell
git add database/migrations/2026_06_03_000001_add_osm_id_to_facilities_table.php app/Models/Facility.php tests/Feature/ImportOsmFacilitiesTest.php
git commit -m "feat(facilities): add osm_id column + Facility fillable update"
```

---

## Task 2: Command — Overpass query + tag mapping + upsert

**Files:**
- Create: `app/Console/Commands/ImportOsmFacilities.php`
- Modify: `tests/Feature/ImportOsmFacilitiesTest.php`

### Context

The command queries the Overpass API via POST with `data=<query>` (form-encoded). The response is JSON with an `elements` array. Each element has `type` (`node` or `way`), `id`, lat/lon (or `center.lat`/`center.lon` for ways), and `tags`.

The Laravel Http facade is already imported in other commands via `use Illuminate\Support\Facades\Http;`. In tests, use `Http::fake(['*overpass*' => Http::response(...)])`.

The Overpass endpoint defaults to `https://overpass-api.de/api/interpreter` and is overridable via `OVERPASS_API_URL` env var.

- [ ] **Step 1: Write the failing test**

Add these tests to `tests/Feature/ImportOsmFacilitiesTest.php`:

```php
#[Test]
public function command_imports_hospital_node_from_overpass(): void
{
    Http::fake([
        '*overpass*' => Http::response($this->overpassResponse([
            $this->osmNode(11111111, 14.2717, 121.4554, [
                'amenity' => 'hospital',
                'name' => 'Pagsanjan District Hospital',
            ]),
        ]), 200),
    ]);

    $this->artisan('facilities:import-osm')->assertSuccessful();

    $facility = Facility::where('osm_id', 'node:11111111')->first();

    $this->assertNotNull($facility, 'Hospital facility should have been created');
    $this->assertSame('Hospital', $facility->type);
    $this->assertSame('Pagsanjan District Hospital', $facility->name);
    $this->assertSame('openstreetmap', $facility->source);
    $this->assertTrue((bool) $facility->is_active);
    $this->assertEqualsWithDelta(14.2717, (float) $facility->latitude, 0.0001);
    $this->assertEqualsWithDelta(121.4554, (float) $facility->longitude, 0.0001);
}

#[Test]
public function command_imports_way_using_center_coordinates(): void
{
    Http::fake([
        '*overpass*' => Http::response($this->overpassResponse([
            $this->osmWay(22222222, 14.2698, 121.4547, [
                'amenity' => 'marketplace',
                'name' => 'Pagsanjan Public Market',
            ]),
        ]), 200),
    ]);

    $this->artisan('facilities:import-osm')->assertSuccessful();

    $facility = Facility::where('osm_id', 'way:22222222')->first();

    $this->assertNotNull($facility);
    $this->assertSame('Public Market', $facility->type);
    $this->assertEqualsWithDelta(14.2698, (float) $facility->latitude, 0.0001);
}

#[Test]
public function command_skips_node_with_no_mappable_type(): void
{
    Http::fake([
        '*overpass*' => Http::response($this->overpassResponse([
            $this->osmNode(33333333, 14.2717, 121.4554, ['amenity' => 'bench']),
        ]), 200),
    ]);

    $this->artisan('facilities:import-osm')->assertSuccessful();

    $this->assertNull(Facility::where('osm_id', 'node:33333333')->first());
}

#[Test]
public function command_handles_overpass_api_failure_gracefully(): void
{
    Http::fake([
        '*overpass*' => Http::response('Service Unavailable', 503),
    ]);

    $this->artisan('facilities:import-osm')->assertFailed();

    $this->assertSame(0, Facility::whereNotNull('osm_id')->count());
}
```

- [ ] **Step 2: Run tests to verify they fail**

```powershell
php artisan test tests/Feature/ImportOsmFacilitiesTest.php --stop-on-failure
```

Expected: FAIL — command class does not exist.

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/ImportOsmFacilities.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Facility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ImportOsmFacilities extends Command
{
    protected $signature = 'facilities:import-osm
        {--dry-run : Preview changes without writing to database}
        {--force : Re-import facilities that already have an osm_id}
        {--no-supersede : Skip deactivating matched approximate facilities}';

    protected $description = 'Import facility data from OpenStreetMap Overpass API for Pagsanjan.';

    private const SUPERSEDE_RADIUS_METERS = 50;

    private ?array $barangayFeatures = null;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $noSupersede = (bool) $this->option('no-supersede');

        $this->info('Querying Overpass API for Pagsanjan amenities...');
        if ($dryRun) {
            $this->line('<fg=yellow>Dry run — no changes will be written.</>');
        }

        $elements = $this->queryOverpass();
        if ($elements === null) {
            return self::FAILURE;
        }

        $this->info('Fetched '.count($elements).' nodes/ways from OpenStreetMap.');
        $this->newLine();
        $this->line('Importing...');

        $stats = ['fetched' => count($elements), 'imported' => 0, 'updated' => 0, 'superseded' => 0, 'skipped' => 0];

        foreach ($elements as $element) {
            $data = $this->buildFacilityData($element);

            if ($data === null) {
                $stats['skipped']++;
                continue;
            }

            $osmId = $data['osm_id'];
            $existing = Facility::where('osm_id', $osmId)->first();

            if ($existing && ! $force) {
                $stats['skipped']++;
                continue;
            }

            $isNew = $existing === null;
            $status = $isNew ? '<fg=green>new</>' : '<fg=cyan>updated</>';

            if (! $dryRun) {
                if ($isNew) {
                    Facility::create(array_merge($data, ['source' => 'openstreetmap', 'is_active' => true]));
                } else {
                    // --force: update name/type/coordinates/address only; never restore is_active
                    $existing->update(array_intersect_key($data, array_flip([
                        'name', 'type', 'address', 'latitude', 'longitude',
                    ])));
                }
            }

            if ($isNew) {
                $stats['imported']++;
                if (! $noSupersede && ! $dryRun) {
                    $stats['superseded'] += $this->supersedeApproximate($data);
                }
            } else {
                $stats['updated']++;
            }

            $pad = fn (string $s, int $n) => mb_str_pad($s, $n);
            $this->line(sprintf('  %s  %s  %s  [%s]',
                $pad($data['name'], 38),
                $pad($data['type'], 20),
                $pad($data['barangay'] ?? '—', 26),
                $status
            ));
        }

        $this->newLine();
        $this->line('Done.');
        $this->table(
            ['Fetched', 'Imported', 'Updated', 'Superseded', 'Skipped'],
            [[$stats['fetched'], $stats['imported'], $stats['updated'], $stats['superseded'], $stats['skipped']]]
        );

        return self::SUCCESS;
    }

    private function queryOverpass(): ?array
    {
        $url = config('services.overpass.url', 'https://overpass-api.de/api/interpreter');

        try {
            $response = Http::withHeaders(['User-Agent' => 'AgeSense-OSCA/1.0 (osca-agesense)'])
                ->timeout(40)
                ->retry(2, 3000)
                ->asForm()
                ->post($url, ['data' => $this->overpassQuery()]);

            if (! $response->successful()) {
                $this->error("Overpass API returned HTTP {$response->status()}. Aborting — no data changed.");
                return null;
            }

            return $response->json('elements', []);
        } catch (\Exception $e) {
            $this->error('Overpass API request failed: '.$e->getMessage());
            return null;
        }
    }

    private function overpassQuery(): string
    {
        $bbox = '14.255,121.435,14.290,121.475';

        return '[out:json][timeout:30];'
            .'(node["amenity"~"hospital|clinic|doctors|health_centre|nursing_home|pharmacy|place_of_worship|marketplace|community_centre|social_facility|townhall|bus_station|taxi"]('.$bbox.');'
            .'node["office"="government"]('.$bbox.');'
            .'node["shop"~"chemist|supermarket|convenience|general|market"]('.$bbox.');'
            .'node["highway"="bus_stop"]('.$bbox.');'
            .'way["amenity"~"hospital|marketplace|community_centre|townhall"]('.$bbox.');'
            .'way["shop"~"supermarket|market"]('.$bbox.');'
            .')->.results;.results out center tags;';
    }

    private function buildFacilityData(array $element): ?array
    {
        $elementType = $element['type'] ?? null;

        if ($elementType === 'node') {
            $lat = isset($element['lat']) ? (float) $element['lat'] : null;
            $lon = isset($element['lon']) ? (float) $element['lon'] : null;
        } elseif ($elementType === 'way') {
            $lat = isset($element['center']['lat']) ? (float) $element['center']['lat'] : null;
            $lon = isset($element['center']['lon']) ? (float) $element['center']['lon'] : null;
        } else {
            return null;
        }

        if ($lat === null || $lon === null || ! is_finite($lat) || ! is_finite($lon)) {
            return null;
        }

        $tags = $element['tags'] ?? [];
        $type = $this->mapOsmType($tags);

        if ($type === null) {
            return null;
        }

        $osmId = $elementType.':'.$element['id'];
        $barangay = $this->assignBarangay($lat, $lon);

        return [
            'osm_id'   => $osmId,
            'name'     => $this->resolveName($tags, $type, $barangay),
            'type'     => $type,
            'barangay' => $barangay,
            'address'  => $this->resolveAddress($tags, $barangay),
            'latitude' => round($lat, 7),
            'longitude'=> round($lon, 7),
        ];
    }

    private function mapOsmType(array $tags): ?string
    {
        $amenity = $tags['amenity'] ?? null;
        $office  = $tags['office'] ?? null;
        $shop    = $tags['shop'] ?? null;
        $highway = $tags['highway'] ?? null;
        $name    = strtolower($tags['name'] ?? '');

        if ($amenity === 'hospital') return 'Hospital';
        if (in_array($amenity, ['clinic', 'doctors', 'health_centre', 'nursing_home'], true)) return 'Health Center';
        if ($amenity === 'pharmacy' || $shop === 'chemist') return 'Pharmacy';
        if ($amenity === 'place_of_worship') return 'Church';
        if ($amenity === 'marketplace' || $shop === 'market') return 'Public Market';
        if ($shop === 'supermarket') return 'Supermarket';
        if (in_array($shop, ['convenience', 'general'], true)) return 'Community Store';

        if ($amenity === 'townhall' || $office === 'government') {
            if (str_contains($name, 'municipal')) return 'Municipal Hall';
            return 'Barangay Hall';
        }

        if ($amenity === 'community_centre') {
            return str_contains($name, 'senior') ? 'Senior Center' : 'Community Store';
        }

        if ($amenity === 'social_facility') return 'Community Store';
        if ($amenity === 'bus_station') return 'Transport Hub';

        if ($highway === 'bus_stop' || $amenity === 'taxi') {
            return str_contains($name, 'jeepney') ? 'Jeepney Terminal' : 'Transport Hub';
        }

        return null;
    }

    private function resolveName(array $tags, string $type, ?string $barangay): string
    {
        return $tags['name']
            ?? $tags['name:en']
            ?? $tags['operator']
            ?? ($type.($barangay ? ' — '.$barangay : ''));
    }

    private function resolveAddress(array $tags, ?string $barangay): string
    {
        $parts = array_filter([
            isset($tags['addr:housenumber']) ? $tags['addr:housenumber'] : null,
            isset($tags['addr:street']) ? $tags['addr:street'] : null,
        ]);

        if ($parts) {
            return implode(' ', $parts).', Pagsanjan, Laguna';
        }

        return ($barangay ? $barangay.', ' : '').'Pagsanjan, Laguna';
    }

    private function assignBarangay(float $lat, float $lon): ?string
    {
        foreach ($this->barangayFeatures() as $feature) {
            if ($this->pointInsideFeature([$lon, $lat], $feature)) {
                $p = $feature['properties'] ?? [];

                return (string) ($p['name'] ?? $p['NAME'] ?? $p['barangay'] ?? $p['BARANGAY']
                    ?? $p['brgy_name'] ?? $p['BRGY_NAME'] ?? $p['ADM4_EN'] ?? $p['adm4_en'] ?? '');
            }
        }

        return null;
    }

    private function supersedeApproximate(array $data): int
    {
        $candidates = Facility::where('source', 'sample_prototype_approximate')
            ->where('type', $data['type'])
            ->get();

        $count = 0;
        foreach ($candidates as $candidate) {
            $distance = $this->haversineDistance(
                $data['latitude'], $data['longitude'],
                (float) $candidate->latitude, (float) $candidate->longitude
            );

            if ($distance <= self::SUPERSEDE_RADIUS_METERS) {
                $candidate->update([
                    'is_active' => false,
                    'source'    => 'sample_prototype_approximate_superseded',
                ]);
                $count++;
            }
        }

        return $count;
    }

    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r    = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * asin(sqrt($a));
    }

    private function barangayFeatures(): array
    {
        if ($this->barangayFeatures !== null) {
            return $this->barangayFeatures;
        }

        $path = 'gis/boundaries/pagsanjan_barangays.geojson';
        if (! Storage::disk('local')->exists($path)) {
            return $this->barangayFeatures = [];
        }

        $decoded = json_decode(Storage::disk('local')->get($path), true);

        return $this->barangayFeatures = (is_array($decoded) && isset($decoded['features']))
            ? $decoded['features']
            : [];
    }

    private function pointInsideFeature(array $point, array $feature): bool
    {
        $rings = $this->polygonRings($feature);

        return $rings ? $this->pointInsideRings($point, $rings) : false;
    }

    private function polygonRings(array $feature): array
    {
        $geometry    = $feature['geometry'] ?? null;
        $type        = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;

        if ($type === 'Polygon' && is_array($coordinates)) {
            return $coordinates;
        }

        if ($type === 'MultiPolygon' && is_array($coordinates)) {
            $largestPolygon = [];
            $largestArea    = -1.0;

            foreach ($coordinates as $polygon) {
                if (! is_array($polygon) || ! isset($polygon[0])) {
                    continue;
                }

                $area = abs($this->ringSignedArea($polygon[0]));
                if ($area > $largestArea) {
                    $largestPolygon = $polygon;
                    $largestArea    = $area;
                }
            }

            return $largestPolygon;
        }

        return [];
    }

    private function pointInsideRings(array $point, array $rings): bool
    {
        if (! $rings || ! $this->pointInsideRing($point, $rings[0])) {
            return false;
        }

        foreach (array_slice($rings, 1) as $hole) {
            if ($this->pointInsideRing($point, $hole)) {
                return false;
            }
        }

        return true;
    }

    private function pointInsideRing(array $point, array $ring): bool
    {
        $x      = (float) $point[0];
        $y      = (float) $point[1];
        $inside = false;
        $count  = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            if (! is_array($ring[$i]) || ! is_array($ring[$j])
                || count($ring[$i]) < 2 || count($ring[$j]) < 2) {
                continue;
            }

            $xi = (float) $ring[$i][0];
            $yi = (float) $ring[$i][1];
            $xj = (float) $ring[$j][0];
            $yj = (float) $ring[$j][1];

            $intersects = (($yi > $y) !== ($yj > $y))
                && ($x < (($xj - $xi) * ($y - $yi)) / (($yj - $yi) ?: PHP_FLOAT_EPSILON) + $xi);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private function ringSignedArea(array $ring): float
    {
        $area  = 0.0;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            if (! is_array($ring[$i]) || ! is_array($ring[$j])
                || count($ring[$i]) < 2 || count($ring[$j]) < 2) {
                continue;
            }

            $area += ((float) $ring[$j][0] * (float) $ring[$i][1])
                   - ((float) $ring[$i][0] * (float) $ring[$j][1]);
        }

        return $area / 2;
    }
}
```

- [ ] **Step 4: Add Overpass config entry to `config/services.php`**

Add to the array in `config/services.php` (alongside the existing `openrouteservice` entry):

```php
'overpass' => [
    'url' => env('OVERPASS_API_URL', 'https://overpass-api.de/api/interpreter'),
],
```

- [ ] **Step 5: Run tests — expect pass**

```powershell
php artisan test tests/Feature/ImportOsmFacilitiesTest.php
```

Expected: 5 PASS (1 from Task 1 + 4 new)

- [ ] **Step 6: Run full suite**

```powershell
php artisan test
```

Expected: all pass.

- [ ] **Step 7: Commit**

```powershell
git add app/Console/Commands/ImportOsmFacilities.php config/services.php tests/Feature/ImportOsmFacilitiesTest.php
git commit -m "feat(facilities): add facilities:import-osm command with Overpass query and tag mapping"
```

---

## Task 3: Barangay assignment via real GeoJSON

**Files:**
- Modify: `tests/Feature/ImportOsmFacilitiesTest.php`

### Context

The `assignBarangay()` method uses the real `storage/app/gis/boundaries/pagsanjan_barangays.geojson` file (already present at that path). Test it by importing a node with coordinates known to fall inside a specific barangay.

Coordinates known to be inside **Sabang**: `lat = 14.2750, lon = 121.4523` (the Sabang Church approximate location from the seeder).

- [ ] **Step 1: Add the barangay assignment test**

Add to `tests/Feature/ImportOsmFacilitiesTest.php`:

```php
#[Test]
public function command_assigns_barangay_via_point_in_polygon(): void
{
    Http::fake([
        '*overpass*' => Http::response($this->overpassResponse([
            // Coordinates inside Sabang barangay
            $this->osmNode(44444444, 14.2750, 121.4523, [
                'amenity' => 'place_of_worship',
                'name'    => 'Sabang Chapel',
            ]),
        ]), 200),
    ]);

    $this->artisan('facilities:import-osm')->assertSuccessful();

    $facility = Facility::where('osm_id', 'node:44444444')->first();

    $this->assertNotNull($facility);
    $this->assertSame('Sabang', $facility->barangay);
}

#[Test]
public function command_sets_null_barangay_for_node_outside_all_polygons(): void
{
    Http::fake([
        '*overpass*' => Http::response($this->overpassResponse([
            // Coordinates well outside Pagsanjan
            $this->osmNode(55555555, 14.0000, 121.0000, [
                'amenity' => 'hospital',
                'name'    => 'Far Away Hospital',
            ]),
        ]), 200),
    ]);

    $this->artisan('facilities:import-osm')->assertSuccessful();

    $facility = Facility::where('osm_id', 'node:55555555')->first();

    $this->assertNotNull($facility);
    $this->assertNull($facility->barangay);
}
```

- [ ] **Step 2: Run tests — expect pass**

```powershell
php artisan test tests/Feature/ImportOsmFacilitiesTest.php
```

Expected: 7 PASS

If the Sabang barangay test fails with `barangay = null`, the GeoJSON file may not include Sabang at those coordinates. In that case, check the GeoJSON file and adjust the test coordinates to a point confirmed to be inside any barangay:

```powershell
# Quick check — run tinker and test coordinates manually
php artisan tinker
# Then test with known coordinates from GeocodeSeniors::barangayAnchors():
# Sabang anchor: [14.2752, 121.4529]
```

- [ ] **Step 3: Commit**

```powershell
git add tests/Feature/ImportOsmFacilitiesTest.php
git commit -m "test(facilities): verify barangay assignment via point-in-polygon"
```

---

## Task 4: Flag behaviour — `--dry-run`, `--force`, `--no-supersede`

**Files:**
- Modify: `tests/Feature/ImportOsmFacilitiesTest.php`

### Context

Verify the three flags work correctly:
- `--dry-run`: runs the full logic, prints output, but writes nothing to the DB
- `--force`: re-imports an existing `osm_id` record (updates it)
- `--no-supersede`: skips the approximate deactivation step

- [ ] **Step 1: Add flag tests**

Add to `tests/Feature/ImportOsmFacilitiesTest.php`:

```php
#[Test]
public function dry_run_flag_prevents_any_database_writes(): void
{
    Http::fake([
        '*overpass*' => Http::response($this->overpassResponse([
            $this->osmNode(66666661, 14.2717, 121.4554, [
                'amenity' => 'hospital',
                'name'    => 'Dry Run Hospital',
            ]),
        ]), 200),
    ]);

    $this->artisan('facilities:import-osm --dry-run')->assertSuccessful();

    $this->assertNull(Facility::where('osm_id', 'node:66666661')->first());
}

#[Test]
public function force_flag_updates_existing_osm_facility(): void
{
    Facility::create([
        'osm_id'    => 'node:77777771',
        'name'      => 'Old Name',
        'type'      => 'Hospital',
        'barangay'  => null,
        'address'   => 'Old Address',
        'latitude'  => 14.2700,
        'longitude' => 121.4550,
        'source'    => 'openstreetmap',
        'is_active' => true,
    ]);

    Http::fake([
        '*overpass*' => Http::response($this->overpassResponse([
            $this->osmNode(77777771, 14.2717, 121.4554, [
                'amenity' => 'hospital',
                'name'    => 'Updated Name',
            ]),
        ]), 200),
    ]);

    $this->artisan('facilities:import-osm --force')->assertSuccessful();

    $facility = Facility::where('osm_id', 'node:77777771')->first();
    $this->assertSame('Updated Name', $facility->name);
    $this->assertEqualsWithDelta(14.2717, (float) $facility->latitude, 0.0001);
}

#[Test]
public function without_force_existing_osm_facility_is_skipped(): void
{
    Facility::create([
        'osm_id'    => 'node:88888881',
        'name'      => 'Existing Name',
        'type'      => 'Hospital',
        'barangay'  => null,
        'address'   => 'Existing Address',
        'latitude'  => 14.2700,
        'longitude' => 121.4550,
        'source'    => 'openstreetmap',
        'is_active' => true,
    ]);

    Http::fake([
        '*overpass*' => Http::response($this->overpassResponse([
            $this->osmNode(88888881, 14.2717, 121.4554, [
                'amenity' => 'hospital',
                'name'    => 'Would-Be Updated Name',
            ]),
        ]), 200),
    ]);

    $this->artisan('facilities:import-osm')->assertSuccessful();

    $facility = Facility::where('osm_id', 'node:88888881')->first();
    $this->assertSame('Existing Name', $facility->name);
}
```

- [ ] **Step 2: Run tests — expect pass**

```powershell
php artisan test tests/Feature/ImportOsmFacilitiesTest.php
```

Expected: 10 PASS

- [ ] **Step 3: Commit**

```powershell
git add tests/Feature/ImportOsmFacilitiesTest.php
git commit -m "test(facilities): verify --dry-run, --force, and skip-existing behaviour"
```

---

## Task 5: Approximate supersession

**Files:**
- Modify: `tests/Feature/ImportOsmFacilitiesTest.php`

### Context

When a new OSM facility is imported, the command finds all `source = 'sample_prototype_approximate'` facilities of the same type within 50 m and deactivates them (`is_active = false`, `source = 'sample_prototype_approximate_superseded'`).

The `--no-supersede` flag skips this step entirely.

- [ ] **Step 1: Add supersession tests**

Add to `tests/Feature/ImportOsmFacilitiesTest.php`:

```php
#[Test]
public function approximate_facility_within_50m_is_superseded(): void
{
    // Create an approximate health center very close to the OSM node we will import
    $approximate = Facility::create([
        'name'      => 'Approximate Health Post',
        'type'      => 'Health Center',
        'barangay'  => 'Sabang',
        'address'   => 'Approx, Pagsanjan',
        'latitude'  => 14.2755,   // ~33m from the OSM node at 14.2753
        'longitude' => 121.4527,
        'source'    => 'sample_prototype_approximate',
        'is_active' => true,
    ]);

    Http::fake([
        '*overpass*' => Http::response($this->overpassResponse([
            $this->osmNode(99999991, 14.2753, 121.4527, [
                'amenity' => 'health_centre',
                'name'    => 'Sabang RHU',
            ]),
        ]), 200),
    ]);

    $this->artisan('facilities:import-osm')->assertSuccessful();

    $approximate->refresh();
    $this->assertFalse((bool) $approximate->is_active, 'Approximate facility should be deactivated');
    $this->assertSame('sample_prototype_approximate_superseded', $approximate->source);
}

#[Test]
public function approximate_facility_beyond_50m_is_not_superseded(): void
{
    // ~220m away — outside the 50m threshold
    $farApproximate = Facility::create([
        'name'      => 'Far Health Post',
        'type'      => 'Health Center',
        'barangay'  => 'Sabang',
        'address'   => 'Far, Pagsanjan',
        'latitude'  => 14.2773,
        'longitude' => 121.4527,
        'source'    => 'sample_prototype_approximate',
        'is_active' => true,
    ]);

    Http::fake([
        '*overpass*' => Http::response($this->overpassResponse([
            $this->osmNode(99999992, 14.2753, 121.4527, [
                'amenity' => 'health_centre',
                'name'    => 'Sabang RHU',
            ]),
        ]), 200),
    ]);

    $this->artisan('facilities:import-osm')->assertSuccessful();

    $farApproximate->refresh();
    $this->assertTrue((bool) $farApproximate->is_active, 'Far approximate should remain active');
    $this->assertSame('sample_prototype_approximate', $farApproximate->source);
}

#[Test]
public function no_supersede_flag_skips_deactivation(): void
{
    $approximate = Facility::create([
        'name'      => 'Protected Approximate',
        'type'      => 'Health Center',
        'barangay'  => 'Sabang',
        'address'   => 'Approx, Pagsanjan',
        'latitude'  => 14.2754,
        'longitude' => 121.4527,
        'source'    => 'sample_prototype_approximate',
        'is_active' => true,
    ]);

    Http::fake([
        '*overpass*' => Http::response($this->overpassResponse([
            $this->osmNode(99999993, 14.2753, 121.4527, [
                'amenity' => 'health_centre',
                'name'    => 'Sabang RHU',
            ]),
        ]), 200),
    ]);

    $this->artisan('facilities:import-osm --no-supersede')->assertSuccessful();

    $approximate->refresh();
    $this->assertTrue((bool) $approximate->is_active, 'Approximate should not be deactivated with --no-supersede');
}
```

- [ ] **Step 2: Run tests — expect pass**

```powershell
php artisan test tests/Feature/ImportOsmFacilitiesTest.php
```

Expected: 13 PASS

- [ ] **Step 3: Run full suite**

```powershell
php artisan test
```

Expected: all pass.

- [ ] **Step 4: Commit**

```powershell
git add tests/Feature/ImportOsmFacilitiesTest.php
git commit -m "test(facilities): verify approximate supersession within 50m and --no-supersede flag"
```

---

## Task 6: Smoke test — run against real Overpass API

No code changes. Verifies the command works end-to-end against the live API.

- [ ] **Step 1: Run full test suite one final time**

```powershell
php artisan test
```

Expected: all pass.

- [ ] **Step 2: Dry-run against live Overpass API**

```powershell
php artisan facilities:import-osm --dry-run
```

Expected: command queries the API, prints a list of found facilities with types and barangays, prints stats. No DB changes. If the API is slow or times out, retry — Overpass can be rate-limited during peak hours.

- [ ] **Step 3: Run for real**

```powershell
php artisan facilities:import-osm
```

Expected:
- Fetched: some number > 0 (Pagsanjan has mapped facilities in OSM)
- Imported: > 0 new records
- Superseded: possibly 0–10 (depends on how close OSM coordinates are to our approximate placements)
- Facilities table now has records with `source = 'openstreetmap'`

- [ ] **Step 4: Verify in database**

```powershell
# Check imported OSM facilities
php artisan tinker --execute="App\Models\Facility::whereNotNull('osm_id')->get(['name','type','barangay','source'])->each(fn(\$f) => dump(\$f->toArray()));"
```

Expected: list of facilities with `source = openstreetmap` and real names.

- [ ] **Step 5: Verify GIS map**

Start the app (`.\start.bat`) and open http://127.0.0.1:8000/reports/gis. Switch to "Senior Distribution Points". Verify facility markers appear spread across more barangays than before.
