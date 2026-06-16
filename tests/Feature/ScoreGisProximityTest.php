<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\SeniorAccessibilityMetric;
use App\Models\SeniorCitizen;
use App\Models\SeniorFacilityRouteDistance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The deterministic accessibility score must stay robust against the
 * barangay-centroid routing artifact: ORS routes from un-snapped centroids can
 * be many times the straight-line distance (observed up to 16x), which would
 * otherwise zero out the score for whole barangays. The scorer guards against
 * implausible road routes and recalibrates the distance caps to road scale.
 */
class ScoreGisProximityTest extends TestCase
{
    use DatabaseTransactions;

    /** p50 road/straight detour in Pagsanjan; caps are widened by this factor. */
    private const ROAD_DETOUR_FACTOR = 1.4;

    protected function setUp(): void
    {
        parent::setUp();
        // Isolate each test's own facility as the only active candidate.
        Facility::query()->update(['is_active' => false]);
    }

    private function makeSenior(float $lat, float $lng): SeniorCitizen
    {
        return SeniorCitizen::create([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => 'Lina',
            'last_name' => 'Marquez',
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
            'latitude' => $lat,
            'longitude' => $lng,
            'location_source' => 'gps_capture',
            'location_accuracy' => 'verified',
        ]);
    }

    private function straightMeters(float $la1, float $lo1, float $la2, float $lo2): float
    {
        $R = 6371000.0;
        $dLat = deg2rad($la2 - $la1);
        $dLng = deg2rad($lo2 - $lo1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLng / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    #[Test]
    public function implausible_cached_road_route_is_ignored_like_no_route(): void
    {
        // Same senior coords + same single health center in both scenarios; the
        // only difference is whether an absurd (10x) cached road route exists.
        $seniorWithBadRoute = $this->makeSenior(14.2700, 121.4500);
        $seniorNoRoute = $this->makeSenior(14.2700, 121.4500);

        $hc = Facility::create([
            'name' => 'Pagsanjan Rural Health Unit',
            'type' => 'Health Center',
            'latitude' => 14.2800,   // due north → pure-latitude offset
            'longitude' => 121.4500,
            'source' => 'seed',
            'is_active' => true,
        ]);

        $straight = $this->straightMeters(14.2700, 121.4500, 14.2800, 121.4500);

        // A grossly inflated road route (the centroid-snapping artifact).
        SeniorFacilityRouteDistance::create([
            'senior_citizen_id' => $seniorWithBadRoute->id,
            'facility_id' => $hc->id,
            'origin_latitude' => 14.2700,
            'origin_longitude' => 121.4500,
            'destination_latitude' => 14.2800,
            'destination_longitude' => 121.4500,
            'route_distance_m' => $straight * 10,   // ratio 10 → must be rejected
            'route_duration_s' => 3000.0,
            'provider' => 'openrouteservice',
            'calculated_at' => now(),
        ]);

        $this->artisan('gis:score-proximity')->assertSuccessful();

        $bad = SeniorAccessibilityMetric::where('senior_citizen_id', $seniorWithBadRoute->id)->first();
        $none = SeniorAccessibilityMetric::where('senior_citizen_id', $seniorNoRoute->id)->first();

        // Rejecting the artifact must make the bad-route senior score identically
        // to the senior with no cached route at all (both use straight-line).
        $this->assertEqualsWithDelta(
            (float) $none->accessibility_score,
            (float) $bad->accessibility_score,
            0.0001,
            'Implausible road route should be ignored, scoring like no cached route.'
        );
    }

    #[Test]
    public function caps_are_recalibrated_to_road_scale(): void
    {
        // Place the health center at exactly the OLD straight-line cap (3000 m).
        // Under the old caps that scored 0; the widened caps must score > 0.
        $senior = $this->makeSenior(14.2700, 121.4500);

        // 3000 m due north ≈ 0.026969° latitude.
        $facLat = 14.2700 + (3000.0 / 6371000.0) * (180.0 / M_PI);
        $hc = Facility::create([
            'name' => 'Pagsanjan Rural Health Unit',
            'type' => 'Health Center',
            'latitude' => $facLat,
            'longitude' => 121.4500,
            'source' => 'seed',
            'is_active' => true,
        ]);

        $straight = $this->straightMeters(14.2700, 121.4500, $facLat, 121.4500);

        $this->artisan('gis:score-proximity')->assertSuccessful();

        $metric = SeniorAccessibilityMetric::where('senior_citizen_id', $senior->id)->first();

        // health_center: cap 3000 m, weight 0.25; total weight 1.0.
        $widenedCap = 3000.0 * self::ROAD_DETOUR_FACTOR;
        $component = max(0.0, 1 - $straight / $widenedCap);
        $expected = round($component * 0.25 / 1.0, 4);

        $this->assertGreaterThan(0.0, (float) $metric->accessibility_score);
        $this->assertEqualsWithDelta($expected, (float) $metric->accessibility_score, 0.0005);
    }
}
