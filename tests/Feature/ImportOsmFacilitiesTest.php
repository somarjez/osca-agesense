<?php

namespace Tests\Feature;

use App\Models\Facility;
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

        // Baseline rather than absolute zero — the dev DB may already contain
        // real osm_id facilities from a genuine import run. On API failure the
        // command must add nothing, so the count stays unchanged.
        $countBefore = Facility::whereNotNull('osm_id')->count();

        $this->artisan('facilities:import-osm')->assertFailed();

        $this->assertSame($countBefore, Facility::whereNotNull('osm_id')->count());
    }

    // -------------------------------------------------------------------------
    // Task 3: Barangay assignment
    // -------------------------------------------------------------------------

    #[Test]
    public function command_assigns_barangay_via_point_in_polygon(): void
    {
        Http::fake([
            '*overpass*' => Http::response($this->overpassResponse([
                // Coordinates confirmed inside Sabang barangay polygon
                $this->osmNode(44444444, 14.2540, 121.4330, [
                    'amenity' => 'place_of_worship',
                    'name' => 'Sabang Chapel',
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
                    'name' => 'Far Away Hospital',
                ]),
            ]), 200),
        ]);

        $this->artisan('facilities:import-osm')->assertSuccessful();

        $facility = Facility::where('osm_id', 'node:55555555')->first();

        $this->assertNotNull($facility);
        $this->assertNull($facility->barangay);
    }

    // -------------------------------------------------------------------------
    // Task 4: Flag behaviour
    // -------------------------------------------------------------------------

    #[Test]
    public function dry_run_flag_prevents_any_database_writes(): void
    {
        Http::fake([
            '*overpass*' => Http::response($this->overpassResponse([
                $this->osmNode(66666661, 14.2717, 121.4554, [
                    'amenity' => 'hospital',
                    'name' => 'Dry Run Hospital',
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
            'osm_id' => 'node:77777771',
            'name' => 'Old Name',
            'type' => 'Hospital',
            'barangay' => null,
            'address' => 'Old Address',
            'latitude' => 14.2700,
            'longitude' => 121.4550,
            'source' => 'openstreetmap',
            'is_active' => true,
        ]);

        Http::fake([
            '*overpass*' => Http::response($this->overpassResponse([
                $this->osmNode(77777771, 14.2717, 121.4554, [
                    'amenity' => 'hospital',
                    'name' => 'Updated Name',
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
            'osm_id' => 'node:88888881',
            'name' => 'Existing Name',
            'type' => 'Hospital',
            'barangay' => null,
            'address' => 'Existing Address',
            'latitude' => 14.2700,
            'longitude' => 121.4550,
            'source' => 'openstreetmap',
            'is_active' => true,
        ]);

        Http::fake([
            '*overpass*' => Http::response($this->overpassResponse([
                $this->osmNode(88888881, 14.2717, 121.4554, [
                    'amenity' => 'hospital',
                    'name' => 'Would-Be Updated Name',
                ]),
            ]), 200),
        ]);

        $this->artisan('facilities:import-osm')->assertSuccessful();

        $facility = Facility::where('osm_id', 'node:88888881')->first();
        $this->assertSame('Existing Name', $facility->name);
    }

    // -------------------------------------------------------------------------
    // Task 5: Approximate supersession
    // -------------------------------------------------------------------------

    #[Test]
    public function approximate_facility_within_50m_is_superseded(): void
    {
        // ~22m from the OSM node at 14.2753, 121.4527
        $approximate = Facility::create([
            'name' => 'Approximate Health Post',
            'type' => 'Health Center',
            'barangay' => null,
            'address' => 'Approx, Pagsanjan',
            'latitude' => 14.2755,
            'longitude' => 121.4527,
            'source' => 'sample_prototype_approximate',
            'is_active' => true,
        ]);

        Http::fake([
            '*overpass*' => Http::response($this->overpassResponse([
                $this->osmNode(99999991, 14.2753, 121.4527, [
                    'amenity' => 'health_centre',
                    'name' => 'Sabang RHU',
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
        // ~222m away from the OSM node at 14.2753, 121.4527
        $farApproximate = Facility::create([
            'name' => 'Far Health Post',
            'type' => 'Health Center',
            'barangay' => null,
            'address' => 'Far, Pagsanjan',
            'latitude' => 14.2773,
            'longitude' => 121.4527,
            'source' => 'sample_prototype_approximate',
            'is_active' => true,
        ]);

        Http::fake([
            '*overpass*' => Http::response($this->overpassResponse([
                $this->osmNode(99999992, 14.2753, 121.4527, [
                    'amenity' => 'health_centre',
                    'name' => 'Sabang RHU',
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
            'name' => 'Protected Approximate',
            'type' => 'Health Center',
            'barangay' => null,
            'address' => 'Approx, Pagsanjan',
            'latitude' => 14.2754,
            'longitude' => 121.4527,
            'source' => 'sample_prototype_approximate',
            'is_active' => true,
        ]);

        Http::fake([
            '*overpass*' => Http::response($this->overpassResponse([
                $this->osmNode(99999993, 14.2753, 121.4527, [
                    'amenity' => 'health_centre',
                    'name' => 'Sabang RHU',
                ]),
            ]), 200),
        ]);

        $this->artisan('facilities:import-osm --no-supersede')->assertSuccessful();

        $approximate->refresh();
        $this->assertTrue((bool) $approximate->is_active, 'Approximate should not be deactivated with --no-supersede');
    }
}
