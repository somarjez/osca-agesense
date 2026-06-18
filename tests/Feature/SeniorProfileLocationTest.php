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

        // Tests run against the shared DB (no separate test connection), and the
        // Location panel now ranks ALL active facilities by distance. Deactivate
        // existing facilities so each test's own facility is the only active
        // candidate (rolled back by DatabaseTransactions; UPDATE avoids FK issues).
        Facility::query()->update(['is_active' => false]);
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

    /**
     * The panel now ranks all senior-relevant facilities, so it derives the
     * straight-line distance from coordinates (haversine) rather than the
     * metric's stored per-category value. Mirror that here for the expectation.
     */
    private function expectedDistanceLabel(float $lat1, float $lng1, float $lat2, float $lng2): string
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $meters = $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $meters < 1000
            ? round($meters).' m'
            : number_format($meters / 1000, 1).' km';
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
        // Road distance is now the primary number (matches the GIS map popup),
        // so the distance and the drive time come from the same cached route.
        $response->assertSee('540 m');            // cached ORS road distance, not haversine
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
        // Straight-line still shown, computed from coordinates (haversine).
        $response->assertSee($this->expectedDistanceLabel(14.275000, 121.456000, 14.2719, 121.4551));
        $response->assertDontSee('30 min drive'); // stale 1800s route suppressed
        $response->assertSee('straight-line');    // fell back to straight-line label
    }

    #[Test]
    public function profile_exposes_lazy_route_lookup_data_for_uncached_facilities(): void
    {
        // A senior + facility with NO cached road route: the profile shows the
        // straight-line distance, but must also expose the hooks the client needs
        // to lazily fetch a road route from /api/gis/route-distance (same endpoint
        // the GIS map popup uses), so the straight-line row upgrades to road on view.
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

        // No SeniorFacilityRouteDistance row — this pair is uncached.

        $response = $this->actingAs($this->admin)->get(route('seniors.show', $senior));

        $response->assertOk();
        $response->assertSee('Pagsanjan Rural Health Unit');
        $response->assertSee('straight-line');                       // current fallback
        // Lazy-lookup hooks for the client:
        $response->assertSee(route('api.gis.route-distance', [], false), false);
        $response->assertSee('data-senior-id="'.$senior->id.'"', false);
        $response->assertSee('data-needs-route="1"', false);
        $response->assertSee('data-facility-id="'.$healthCenter->id.'"', false);
    }

    #[Test]
    public function profile_does_not_flag_already_cached_facility_for_route_lookup(): void
    {
        // A facility with a fresh cached road route already shows road distance,
        // so it must NOT be flagged for a (redundant) live lookup.
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
        $response->assertSee('540 m');                 // cached road distance
        // The per-row lazy-lookup attributes (data-facility-id / data-dest-*) are
        // emitted only on rows that fell back to straight-line. A cached row must
        // carry none. (We assert on data-facility-id rather than data-needs-route
        // because the inline upgrade script references the latter literally.)
        $response->assertDontSee('data-facility-id="'.$healthCenter->id.'"', false);
    }

    #[Test]
    public function profile_renders_cached_road_distance_in_km_and_drive_time(): void
    {
        // Mirrors what staff see in the field: a cached road route renders the
        // road distance (km when >= 1000 m) as the primary number plus the drive
        // time derived from the same cached route (e.g. "15 min drive").
        $senior = $this->makeSenior([
            'latitude' => 14.273000,
            'longitude' => 121.455000,
            'location_source' => 'gps_capture',
            'location_accuracy' => 'verified',
        ]);

        $hospital = Facility::create([
            'name' => 'Pagsanjan District Hospital',
            'type' => 'Hospital',
            'latitude' => 14.2801,
            'longitude' => 121.4490,
            'source' => 'seed',
            'is_active' => true,
        ]);

        SeniorAccessibilityMetric::create([
            'senior_citizen_id' => $senior->id,
            'distance_to_hospital_m' => 1200.0,
            'accessibility_score' => 0.55,
            'calculated_at' => now(),
        ]);

        SeniorFacilityRouteDistance::create([
            'senior_citizen_id' => $senior->id,
            'facility_id' => $hospital->id,
            'origin_latitude' => 14.273000,
            'origin_longitude' => 121.455000,
            'destination_latitude' => 14.2801,
            'destination_longitude' => 121.4490,
            'route_distance_m' => 1500.0,   // -> "1.5 km"
            'route_duration_s' => 900.0,    // -> "15 min drive"
            'provider' => 'openrouteservice',
            'calculated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('seniors.show', $senior));

        $response->assertOk();
        $response->assertSee('Pagsanjan District Hospital');
        $response->assertSee('1.5 km');        // road distance, km formatting
        $response->assertSee('15 min drive');  // 900s cached duration
        // A cached row must not be flagged for a redundant lazy lookup. (We can't
        // assertDontSee('straight-line') here: the inline upgrade script mentions
        // that word in a comment, so we assert on the per-row marker instead.)
        $response->assertDontSee('data-facility-id="'.$hospital->id.'"', false);
    }

    #[Test]
    public function profile_labels_cached_route_without_duration_as_by_road(): void
    {
        // A cached road distance with no duration still shows road distance, but
        // the sublabel reads "by road" rather than a drive time.
        $senior = $this->makeSenior([
            'latitude' => 14.273000,
            'longitude' => 121.455000,
            'location_source' => 'gps_capture',
            'location_accuracy' => 'verified',
        ]);

        $pharmacy = Facility::create([
            'name' => 'Botika ng Bayan',
            'type' => 'Pharmacy',
            'latitude' => 14.2725,
            'longitude' => 121.4548,
            'source' => 'seed',
            'is_active' => true,
        ]);

        SeniorAccessibilityMetric::create([
            'senior_citizen_id' => $senior->id,
            'distance_to_pharmacy_m' => 300.0,
            'accessibility_score' => 0.7,
            'calculated_at' => now(),
        ]);

        SeniorFacilityRouteDistance::create([
            'senior_citizen_id' => $senior->id,
            'facility_id' => $pharmacy->id,
            'origin_latitude' => 14.273000,
            'origin_longitude' => 121.455000,
            'destination_latitude' => 14.2725,
            'destination_longitude' => 121.4548,
            'route_distance_m' => 800.0,    // -> "800 m"
            'route_duration_s' => null,     // no duration -> "by road"
            'provider' => 'openrouteservice',
            'calculated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('seniors.show', $senior));

        $response->assertOk();
        $response->assertSee('800 m');
        $response->assertSee('by road');
        // No lazy-lookup marker: this row already has a cached road route.
        // (The inline script contains the literals "min drive"/"straight-line",
        // so we verify via the per-row marker rather than assertDontSee on text.)
        $response->assertDontSee('data-facility-id="'.$pharmacy->id.'"', false);
    }

    #[Test]
    public function profile_mixes_road_routes_and_straight_line_rows_correctly(): void
    {
        // Two facilities of different types: one with a fresh cached route (road),
        // one without (straight-line + lazy-lookup hooks). Only the uncached row
        // should carry the per-row lazy attributes.
        $senior = $this->makeSenior([
            'latitude' => 14.273000,
            'longitude' => 121.455000,
            'location_source' => 'gps_capture',
            'location_accuracy' => 'verified',
        ]);

        $cached = Facility::create([
            'name' => 'Pagsanjan Rural Health Unit',
            'type' => 'Health Center',
            'latitude' => 14.2719,
            'longitude' => 121.4551,
            'source' => 'seed',
            'is_active' => true,
        ]);

        $uncached = Facility::create([
            'name' => 'Pagsanjan Public Market',
            'type' => 'Public Market',
            'latitude' => 14.2740,
            'longitude' => 121.4560,
            'source' => 'seed',
            'is_active' => true,
        ]);

        SeniorAccessibilityMetric::create([
            'senior_citizen_id' => $senior->id,
            'nearest_health_center_id' => $cached->id,
            'distance_to_health_center_m' => 320.0,
            'accessibility_score' => 0.66,
            'calculated_at' => now(),
        ]);

        SeniorFacilityRouteDistance::create([
            'senior_citizen_id' => $senior->id,
            'facility_id' => $cached->id,
            'origin_latitude' => 14.273000,
            'origin_longitude' => 121.455000,
            'destination_latitude' => 14.2719,
            'destination_longitude' => 121.4551,
            'route_distance_m' => 540.0,
            'route_duration_s' => 240.0,
            'provider' => 'openrouteservice',
            'calculated_at' => now(),
        ]);
        // No route row for $uncached -> straight-line + lazy hooks.

        $response = $this->actingAs($this->admin)->get(route('seniors.show', $senior));

        $response->assertOk();
        $response->assertSee('540 m');          // cached row -> road
        $response->assertSee('4 min drive');
        $response->assertSee('straight-line');  // uncached row fell back
        // Only the uncached facility is flagged for a lazy road-route lookup.
        $response->assertSee('data-facility-id="'.$uncached->id.'"', false);
        $response->assertDontSee('data-facility-id="'.$cached->id.'"', false);
    }

    #[Test]
    public function profile_lazy_attributes_carry_origin_and_destination_coordinates(): void
    {
        // The client needs both endpoints to request a road route, and they must
        // match the coordinates the controller uses for cache-freshness so a
        // fetched route is treated as fresh on the next render.
        $senior = $this->makeSenior([
            'latitude' => 14.273000,
            'longitude' => 121.455000,
            'location_source' => 'gps_capture',
            'location_accuracy' => 'verified',
        ]);

        $facility = Facility::create([
            'name' => 'Pagsanjan Rural Health Unit',
            'type' => 'Health Center',
            'latitude' => 14.2719,
            'longitude' => 121.4551,
            'source' => 'seed',
            'is_active' => true,
        ]);

        SeniorAccessibilityMetric::create([
            'senior_citizen_id' => $senior->id,
            'nearest_health_center_id' => $facility->id,
            'distance_to_health_center_m' => 320.0,
            'accessibility_score' => 0.7,
            'calculated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('seniors.show', $senior));

        $response->assertOk();
        $response->assertSee('data-origin-lat="14.273"', false);
        $response->assertSee('data-origin-lng="121.455"', false);
        $response->assertSee('data-dest-lat="14.2719"', false);
        $response->assertSee('data-dest-lng="121.4551"', false);
        $response->assertSee('data-senior-id="'.$senior->id.'"', false);
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
