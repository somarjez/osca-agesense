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
