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

    #[Test]
    public function seniors_geojson_second_request_served_from_cache_without_db_queries(): void
    {
        Cache::forget('gis.seniors_geojson');

        // First request — populates cache
        $this->actingAs($this->admin)
            ->getJson('/api/gis/seniors')
            ->assertStatus(200);

        $this->assertTrue(Cache::has('gis.seniors_geojson'));

        // Second request — must be served from cache (no DB queries)
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $this->actingAs($this->admin)
            ->getJson('/api/gis/seniors')
            ->assertStatus(200)
            ->assertJsonStructure(['type', 'features']);

        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // Filter out any permission/auth queries — we only care that the seniors
        // GeoJSON build (the expensive part) did not hit the DB.
        $seniorQueries = array_filter($queries, fn($q) =>
            str_contains(strtolower($q['query']), 'senior_citizens')
        );

        $this->assertEmpty($seniorQueries,
            'Second request should not query senior_citizens — it should be served from cache. Queries fired: '
            . implode('; ', array_column($seniorQueries, 'query'))
        );
    }

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
}
