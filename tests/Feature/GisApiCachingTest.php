<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SeniorDataVersion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GisApiCachingTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $viewer;

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

        $this->viewer = User::firstOrCreate(
            ['email' => 'viewer@osca.local'],
            ['name' => 'OSCA Viewer', 'password' => bcrypt('password')]
        );
        $this->viewer->syncRoles(['viewer']);
    }

    #[Test]
    public function seniors_geojson_is_stored_in_cache_after_first_request(): void
    {
        $key = 'gis.seniors_geojson.full.'.SeniorDataVersion::current();
        Cache::forget($key);
        $this->assertFalse(Cache::has($key));

        $this->actingAs($this->admin)
            ->getJson('/api/gis/seniors')
            ->assertStatus(200)
            ->assertJsonStructure(['type', 'features']);

        $this->assertTrue(Cache::has($key));
    }

    #[Test]
    public function barangay_filter_stores_separate_cache_key(): void
    {
        $key = 'gis.seniors_geojson.full.'.SeniorDataVersion::current().'.'.md5('Sabang');
        Cache::forget($key);
        $this->assertFalse(Cache::has($key));

        $this->actingAs($this->admin)
            ->getJson('/api/gis/seniors?barangay=Sabang')
            ->assertStatus(200)
            ->assertJsonStructure(['type', 'features']);

        $this->assertTrue(Cache::has($key));
    }

    #[Test]
    public function role_precision_stores_separate_cache_key(): void
    {
        // Admin (full precision) and viewer (generalized) must never share a
        // cache slot — otherwise whichever role's request populates the cache
        // first "wins" for 5 minutes and leaks its precision level to the other.
        $fullKey = 'gis.seniors_geojson.full.'.SeniorDataVersion::current();
        $generalizedKey = 'gis.seniors_geojson.generalized.'.SeniorDataVersion::current();
        Cache::forget($fullKey);
        Cache::forget($generalizedKey);

        $this->actingAs($this->admin)
            ->getJson('/api/gis/seniors')
            ->assertStatus(200);
        $this->actingAs($this->viewer)
            ->getJson('/api/gis/seniors')
            ->assertStatus(200);

        $this->assertTrue(Cache::has($fullKey));
        $this->assertTrue(Cache::has($generalizedKey));
    }

    #[Test]
    public function seniors_geojson_second_request_served_from_cache_without_db_queries(): void
    {
        $key = 'gis.seniors_geojson.full.'.SeniorDataVersion::current();
        Cache::forget($key);

        // First request — populates cache
        $this->actingAs($this->admin)
            ->getJson('/api/gis/seniors')
            ->assertStatus(200);

        $this->assertTrue(Cache::has($key));

        // Second request — must be served from cache (no DB queries)
        DB::enableQueryLog();

        $this->actingAs($this->admin)
            ->getJson('/api/gis/seniors')
            ->assertStatus(200)
            ->assertJsonStructure(['type', 'features']);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Filter out any permission/auth queries — we only care that the seniors
        // GeoJSON build (the expensive part) did not hit the DB.
        $seniorQueries = array_filter($queries, fn ($q) => str_contains(strtolower($q['query']), 'senior_citizens')
        );

        $this->assertEmpty($seniorQueries,
            'Second request should not query senior_citizens — it should be served from cache. Queries fired: '
            .implode('; ', array_column($seniorQueries, 'query'))
        );
    }

    #[Test]
    public function geocode_dispatch_busts_seniors_geojson_cache(): void
    {
        // gis:geocode now runs synchronously in the request (so the "Bulk
        // Geocode Status" badge is fresh on first click — see
        // ReportController::runGisGeocode), but it still chains a queued ORS
        // route recompute per updated senior; fake the queue so this test
        // never makes a real ORS call.
        Queue::fake();

        // Seed the exact key GisApiController::seniors() would currently read
        // with a distinguishable dummy payload.
        $key = 'gis.seniors_geojson.full.'.SeniorDataVersion::current();
        Cache::put($key, ['type' => 'DummyCachedPayload', 'features' => []], now()->addMinutes(5));

        $this->actingAs($this->admin)
            ->post(route('reports.gis.geocode'))
            ->assertRedirect();

        // The controller invalidates via SeniorDataVersion::bump() (same
        // pattern as SeniorLocationObserver), not by forgetting a literal key.
        // That folds a new version into the cache key GisApiController reads
        // next, so the stale dummy payload above is never served again.
        $response = $this->actingAs($this->admin)->getJson('/api/gis/seniors');
        $response->assertStatus(200);
        $this->assertNotSame('DummyCachedPayload', $response->json('type'));
    }
}
