<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\SeniorAccessibilityMetric;
use App\Models\SeniorCitizen;
use App\Models\SeniorFacilityRouteDistance;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The profile's Location & Accessibility panel must render entirely from the
 * precomputed cache tables (accessibility metrics + cached route distances),
 * never from a live OpenRouteService request.
 */
class SeniorProfileLocationTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin@osca.local'],
            ['name' => 'OSCA Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);
    }

    private function makeSenior(array $overrides = []): SeniorCitizen
    {
        return SeniorCitizen::create(array_merge([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => 'Lina',
            'last_name' => 'Marquez',
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
        ], $overrides));
    }

    #[Test]
    public function profile_shows_verified_coordinates_and_cached_facility_distances(): void
    {
        $senior = $this->makeSenior([
            'latitude' => 14.273000,
            'longitude' => 121.455000,
            'location_source' => 'gps_capture',
            'location_accuracy' => 'verified',
        ]);

        $healthCenter = Facility::create([
            'name' => 'Pagsanjan Rural Health Unit',
            'type' => 'Health Center',
            'latitude' => 14.2719,
            'longitude' => 121.4551,
            'source' => 'seed',
            'is_active' => true,
        ]);

        SeniorAccessibilityMetric::create([
            'senior_citizen_id' => $senior->id,
            'nearest_health_center_id' => $healthCenter->id,
            'distance_to_health_center_m' => 320.0,
            'accessibility_score' => 0.78,
            'calculated_at' => now(),
        ]);

        // Cached ORS road distance for this senior/facility pair.
        SeniorFacilityRouteDistance::create([
            'senior_citizen_id' => $senior->id,
            'facility_id' => $healthCenter->id,
            'origin_latitude' => 14.273000,
            'origin_longitude' => 121.455000,
            'destination_latitude' => 14.2719,
            'destination_longitude' => 121.4551,
            'route_distance_m' => 540.0,
            'route_duration_s' => 240.0,
            'provider' => 'openrouteservice',
            'calculated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('seniors.show', $senior));

        $response->assertOk();
        $response->assertSee('Location &amp; Accessibility', false);
        $response->assertSee('14.273000');
        $response->assertSee('121.455000');
        $response->assertSee('Verified pin');
        $response->assertSee('Pagsanjan Rural Health Unit');
        $response->assertSee('320 m');           // straight-line distance
        $response->assertSee('4 min drive');      // 240s cached route duration
        $response->assertSee('78%');              // accessibility score
        $response->assertSee('Good access');
    }

    #[Test]
    public function stale_cached_route_is_ignored_when_senior_has_moved(): void
    {
        // Senior's current pin...
        $senior = $this->makeSenior([
            'latitude' => 14.275000,
            'longitude' => 121.456000,
            'location_source' => 'gps_capture',
            'location_accuracy' => 'verified',
        ]);

        $healthCenter = Facility::create([
            'name' => 'Pagsanjan Rural Health Unit',
            'type' => 'Health Center',
            'latitude' => 14.2719,
            'longitude' => 121.4551,
            'source' => 'seed',
            'is_active' => true,
        ]);

        SeniorAccessibilityMetric::create([
            'senior_citizen_id' => $senior->id,
            'nearest_health_center_id' => $healthCenter->id,
            'distance_to_health_center_m' => 410.0,
            'accessibility_score' => 0.6,
            'calculated_at' => now(),
        ]);

        // ...but the cached route was computed from an OLD origin (senior moved).
        SeniorFacilityRouteDistance::create([
            'senior_citizen_id' => $senior->id,
            'facility_id' => $healthCenter->id,
            'origin_latitude' => 14.270000,   // does not match current 14.275
            'origin_longitude' => 121.450000,
            'destination_latitude' => 14.2719,
            'destination_longitude' => 121.4551,
            'route_distance_m' => 9999.0,
            'route_duration_s' => 1800.0,
            'provider' => 'openrouteservice',
            'calculated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('seniors.show', $senior));

        $response->assertOk();
        $response->assertSee('410 m');           // straight-line still shown
        $response->assertDontSee('30 min drive'); // stale 1800s route suppressed
        $response->assertSee('straight-line');    // fell back to straight-line label
    }

    #[Test]
    public function profile_marks_barangay_level_seniors_as_approximate(): void
    {
        $senior = $this->makeSenior([
            'latitude' => 14.278200,
            'longitude' => 121.458800,
            'location_source' => 'barangay_centroid',
            'location_accuracy' => 'approximate',
        ]);

        $response = $this->actingAs($this->admin)->get(route('seniors.show', $senior));

        $response->assertOk();
        $response->assertSee('Approximate');
        $response->assertSee('no field-verified GPS pin', false);
    }

    #[Test]
    public function profile_handles_senior_without_any_location(): void
    {
        $senior = $this->makeSenior();

        $response = $this->actingAs($this->admin)->get(route('seniors.show', $senior));

        $response->assertOk();
        $response->assertSee('No location on record');
        $response->assertDontSee('id="senior-mini-map"', false);
    }
}
