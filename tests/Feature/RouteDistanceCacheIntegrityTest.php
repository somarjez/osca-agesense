<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\SeniorCitizen;
use App\Models\SeniorFacilityRouteDistance;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fix for the Task 7 review finding: /api/gis/route-distance's underlying
 * write endpoint was unprotected. A viewer — who only ever sees a
 * barangay-generalized origin for a senior (see
 * GisApiController::coordinatesForSenior()/fullPrecision()) — could supply
 * an arbitrary origin_lat/lng alongside a real senior_id + facility_id and
 * have GisApiController::storeRouteDistance() unconditionally overwrite the
 * shared senior_facility_route_distance cache row for that pair, destroying
 * a previously-cached accurate route and wasting external routing-API quota.
 *
 * The fix: GisApiController::shouldPersistRouteDistance() gates the
 * storeRouteDistance() call — when senior_id is present and the caller
 * lacks full precision, persistence only happens if the supplied origin
 * actually matches the senior's real stored coordinates (via the same
 * coordinatesMatch() 1e-6 tolerance helper SeniorCitizenController and
 * ScoreGisProximity mirror). The endpoint still computes and returns a
 * live distance either way — only the cache write is skipped.
 */
class RouteDistanceCacheIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private const SENIOR_LAT = 14.278200;

    private const SENIOR_LNG = 121.458800;

    // Deliberately far from the senior's real coordinates above — this is
    // what a viewer's client would send after generalization, or what a
    // malicious/careless caller could send directly to the endpoint.
    private const ARBITRARY_LAT = 14.100000;

    private const ARBITRARY_LNG = 121.100000;

    private User $admin;

    private User $viewer;

    private SeniorCitizen $senior;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin-route@osca.local'],
            ['name' => 'OSCA Admin', 'password' => bcrypt('password')]
        );
        $this->admin->syncRoles(['admin']);

        $this->viewer = User::firstOrCreate(
            ['email' => 'viewer-route@osca.local'],
            ['name' => 'OSCA Viewer', 'password' => bcrypt('password')]
        );
        $this->viewer->syncRoles(['viewer']);

        $this->senior = SeniorCitizen::create([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => 'Rosa',
            'last_name' => 'Delacruz',
            'barangay' => 'Anibong',
            'date_of_birth' => '1948-03-14',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
            'latitude' => self::SENIOR_LAT,
            'longitude' => self::SENIOR_LNG,
            'location_source' => 'gps_capture',
            'location_accuracy' => 'verified',
        ]);

        $this->facility = Facility::create([
            'name' => 'Anibong Health Center',
            'type' => 'Health Center',
            'barangay' => 'Anibong',
            'address' => 'Anibong',
            'latitude' => 14.280000,
            'longitude' => 121.460000,
            'source' => 'test_fixture',
            'is_active' => true,
        ]);

        Http::fake([
            '*directions*' => Http::response([
                'routes' => [
                    ['summary' => ['distance' => 500.0, 'duration' => 120.0]],
                ],
            ], 200),
        ]);
    }

    private function routeDistanceQuery(float $originLat, float $originLng): array
    {
        return [
            'origin_lat' => $originLat,
            'origin_lng' => $originLng,
            'destination_lat' => (float) $this->facility->latitude,
            'destination_lng' => (float) $this->facility->longitude,
            'senior_id' => $this->senior->id,
            'facility_id' => $this->facility->id,
        ];
    }

    private function cacheRowExistsForPair(): bool
    {
        return SeniorFacilityRouteDistance::query()
            ->where('senior_citizen_id', $this->senior->id)
            ->where('facility_id', $this->facility->id)
            ->exists();
    }

    #[Test]
    public function viewer_supplying_arbitrary_origin_does_not_create_a_cache_row(): void
    {
        $this->assertFalse($this->cacheRowExistsForPair());

        $response = $this->actingAs($this->viewer)->getJson(
            '/api/gis/route-distance?'.http_build_query(
                $this->routeDistanceQuery(self::ARBITRARY_LAT, self::ARBITRARY_LNG)
            )
        );

        $response->assertStatus(200);
        $this->assertEqualsWithDelta(500.0, (float) $response->json('distance'), 0.001);

        $this->assertFalse(
            $this->cacheRowExistsForPair(),
            'A viewer supplying a non-matching origin must not create a route-distance cache row.'
        );
    }

    #[Test]
    public function viewer_supplying_arbitrary_origin_does_not_overwrite_an_existing_cache_row(): void
    {
        $existing = SeniorFacilityRouteDistance::create([
            'senior_citizen_id' => $this->senior->id,
            'facility_id' => $this->facility->id,
            'origin_latitude' => self::SENIOR_LAT,
            'origin_longitude' => self::SENIOR_LNG,
            'destination_latitude' => (float) $this->facility->latitude,
            'destination_longitude' => (float) $this->facility->longitude,
            'route_distance_m' => 123.45,
            'route_duration_s' => 60.0,
            'provider' => 'openrouteservice',
            'calculated_at' => now(),
        ]);

        $response = $this->actingAs($this->viewer)->getJson(
            '/api/gis/route-distance?'.http_build_query(
                $this->routeDistanceQuery(self::ARBITRARY_LAT, self::ARBITRARY_LNG)
            )
        );

        $response->assertStatus(200);

        $existing->refresh();
        $this->assertEqualsWithDelta(123.45, (float) $existing->route_distance_m, 0.001);
        $this->assertEqualsWithDelta(self::SENIOR_LAT, (float) $existing->origin_latitude, 0.0000001);
        $this->assertEqualsWithDelta(self::SENIOR_LNG, (float) $existing->origin_longitude, 0.0000001);
    }

    #[Test]
    public function viewer_supplying_the_real_senior_origin_is_allowed_to_persist(): void
    {
        // The rare non-corruption case: a viewer happens to supply the
        // senior's real coordinates. Not the attack this fix targets, so
        // persistence proceeds as normal.
        $response = $this->actingAs($this->viewer)->getJson(
            '/api/gis/route-distance?'.http_build_query(
                $this->routeDistanceQuery(self::SENIOR_LAT, self::SENIOR_LNG)
            )
        );

        $response->assertStatus(200);
        $this->assertTrue($this->cacheRowExistsForPair());
        $this->assertDatabaseHas('senior_facility_route_distances', [
            'senior_citizen_id' => $this->senior->id,
            'facility_id' => $this->facility->id,
            'route_distance_m' => 500.0,
        ]);
    }

    #[Test]
    public function admin_with_full_precision_persists_exactly_as_before(): void
    {
        $response = $this->actingAs($this->admin)->getJson(
            '/api/gis/route-distance?'.http_build_query(
                $this->routeDistanceQuery(self::SENIOR_LAT, self::SENIOR_LNG)
            )
        );

        $response->assertStatus(200);
        $this->assertTrue($this->cacheRowExistsForPair());
        $this->assertDatabaseHas('senior_facility_route_distances', [
            'senior_citizen_id' => $this->senior->id,
            'facility_id' => $this->facility->id,
            'route_distance_m' => 500.0,
        ]);
    }

    #[Test]
    public function admin_supplying_a_non_matching_origin_still_persists_unaffected_by_this_fix(): void
    {
        // Full-precision users (admin/encoder) are completely unaffected by
        // this fix even if their origin doesn't match the senior's stored
        // coordinates (e.g. computing distance from a different point) —
        // the gate only applies to non-full-precision callers.
        $response = $this->actingAs($this->admin)->getJson(
            '/api/gis/route-distance?'.http_build_query(
                $this->routeDistanceQuery(self::ARBITRARY_LAT, self::ARBITRARY_LNG)
            )
        );

        $response->assertStatus(200);
        $this->assertTrue($this->cacheRowExistsForPair());
        $this->assertDatabaseHas('senior_facility_route_distances', [
            'senior_citizen_id' => $this->senior->id,
            'facility_id' => $this->facility->id,
            'route_distance_m' => 500.0,
        ]);
    }
}
