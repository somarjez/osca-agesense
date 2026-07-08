<?php

namespace Tests\Feature;

use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 7 — GIS coordinate precision by role.
 *
 * Admin/encoder get exact coordinates from /api/gis/seniors (unchanged
 * behavior). Viewer always gets the deterministic barangay-generalized
 * point, even for a senior with a real, field-verified GPS pin.
 *
 * Also proves the cache-key fix directly: before this task, /api/gis/seniors
 * cached its GeoJSON payload under a role-agnostic key, so whichever role's
 * request populated the cache first "won" for 5 minutes — an admin request
 * would cache full-precision coordinates and a viewer request in that same
 * window would be served the admin's exact coordinates from cache. The
 * cache key must now include the effective precision mode.
 */
class GisPrecisionByRoleTest extends TestCase
{
    use DatabaseTransactions;

    private const SENIOR_LAT = 14.278200;

    private const SENIOR_LNG = 121.458800;

    private User $admin;

    private User $viewer;

    private SeniorCitizen $senior;

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

        Cache::forget('gis.seniors_geojson.full');
        Cache::forget('gis.seniors_geojson.generalized');
    }

    private function featureFor(array $payload, SeniorCitizen $senior): ?array
    {
        foreach ($payload['features'] as $feature) {
            if (($feature['properties']['senior_id'] ?? null) === $senior->id) {
                return $feature;
            }
        }

        return null;
    }

    private function isExactStoredPoint(float $lat, float $lng): bool
    {
        return abs($lat - self::SENIOR_LAT) < 0.0000001
            && abs($lng - self::SENIOR_LNG) < 0.0000001;
    }

    #[Test]
    public function admin_sees_exact_stored_coordinates(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/gis/seniors');
        $response->assertStatus(200);

        $feature = $this->featureFor($response->json(), $this->senior);
        $this->assertNotNull($feature, 'Senior feature missing from admin response.');

        [$lng, $lat] = $feature['geometry']['coordinates'];
        $this->assertEqualsWithDelta(self::SENIOR_LAT, $lat, 0.0000001);
        $this->assertEqualsWithDelta(self::SENIOR_LNG, $lng, 0.0000001);
        $this->assertSame('verified', $feature['properties']['location_status']);
    }

    #[Test]
    public function viewer_never_sees_exact_stored_coordinates(): void
    {
        $response = $this->actingAs($this->viewer)->getJson('/api/gis/seniors');
        $response->assertStatus(200);

        $feature = $this->featureFor($response->json(), $this->senior);
        $this->assertNotNull($feature, 'Senior feature missing from viewer response.');

        [$lng, $lat] = $feature['geometry']['coordinates'];
        $this->assertFalse(
            $this->isExactStoredPoint((float) $lat, (float) $lng),
            "Viewer must never receive the senior's exact stored coordinates."
        );
        $this->assertSame('generalized', $feature['properties']['location_status']);
    }

    #[Test]
    public function viewer_generalized_point_is_deterministic_across_requests(): void
    {
        $first = $this->actingAs($this->viewer)->getJson('/api/gis/seniors');
        $first->assertStatus(200);
        $firstCoords = $this->featureFor($first->json(), $this->senior)['geometry']['coordinates'];

        // Force recomputation (bypass the cache) instead of trivially re-serving
        // the same cached payload, so this proves the generalization algorithm
        // itself is deterministic, not just that the cache is sticky.
        Cache::forget('gis.seniors_geojson.generalized');

        $second = $this->actingAs($this->viewer)->getJson('/api/gis/seniors');
        $second->assertStatus(200);
        $secondCoords = $this->featureFor($second->json(), $this->senior)['geometry']['coordinates'];

        $this->assertSame($firstCoords, $secondCoords);
    }

    #[Test]
    public function admin_request_does_not_leak_full_precision_into_viewers_cached_response(): void
    {
        // The specific regression this task fixes: before the cache key included
        // the precision mode, whichever role's request populated the shared cache
        // slot first "won" for 5 minutes.
        $adminResponse = $this->actingAs($this->admin)->getJson('/api/gis/seniors');
        $adminResponse->assertStatus(200);

        $viewerResponse = $this->actingAs($this->viewer)->getJson('/api/gis/seniors');
        $viewerResponse->assertStatus(200);

        $viewerFeature = $this->featureFor($viewerResponse->json(), $this->senior);
        [$lng, $lat] = $viewerFeature['geometry']['coordinates'];

        $this->assertFalse(
            $this->isExactStoredPoint((float) $lat, (float) $lng),
            'Viewer response must not be served the admin-cached full-precision coordinates.'
        );
        $this->assertSame('generalized', $viewerFeature['properties']['location_status']);
    }

    #[Test]
    public function viewer_request_does_not_leak_generalized_precision_into_admins_cached_response(): void
    {
        // Reverse order — viewer first, then admin — must also not cross-leak.
        $viewerResponse = $this->actingAs($this->viewer)->getJson('/api/gis/seniors');
        $viewerResponse->assertStatus(200);

        $adminResponse = $this->actingAs($this->admin)->getJson('/api/gis/seniors');
        $adminResponse->assertStatus(200);

        $adminFeature = $this->featureFor($adminResponse->json(), $this->senior);
        [$lng, $lat] = $adminFeature['geometry']['coordinates'];

        $this->assertEqualsWithDelta(self::SENIOR_LAT, $lat, 0.0000001);
        $this->assertEqualsWithDelta(self::SENIOR_LNG, $lng, 0.0000001);
        $this->assertSame('verified', $adminFeature['properties']['location_status']);
    }
}
