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
}
