<?php

namespace App\Console\Commands;

use App\Models\Facility;
use App\Models\SeniorAccessibilityMetric;
use App\Models\SeniorCitizen;
use App\Models\SeniorFacilityRouteDistance;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ScoreGisProximity extends Command
{
    protected $signature = 'gis:score-proximity
        {--dry-run : Calculate and display scores without saving}
        {--senior-id= : Limit scoring to a single senior_citizens.id}
        {--barangay= : Limit scoring to seniors assigned to one barangay}';

    protected $description = 'Calculate GIS proximity scores from senior coordinates to nearby facilities.';

    /**
     * Median road/straight-line detour observed across the cached Pagsanjan
     * routes (p50 ≈ 1.37). The `cap_m` thresholds below were calibrated for
     * straight-line distance; when a component is scored on road distance the
     * cap is widened by this factor to keep road-based scores on the same scale
     * the caps were designed for. Straight-line fallbacks use the unwidened cap
     * (see scoreSenior()) so they retain the original calibration.
     */
    private const ROAD_DETOUR_FACTOR = 1.4;

    /**
     * Maximum trustworthy road/straight-line ratio. Barangay-centroid origins
     * that don't snap to the OSM routing graph yield absurd routes (observed up
     * to 16x, p90 ≈ 2.5); above this ratio the cached road distance is treated
     * as an artifact and the scorer falls back to straight-line.
     */
    private const MAX_TRUSTED_DETOUR = 3.0;

    private const CATEGORY_CONFIG = [
        'health_center' => [
            'id_column' => 'nearest_health_center_id',
            'distance_column' => 'distance_to_health_center_m',
            'label' => 'health center',
            'cap_m' => 3000,
            'weight' => 0.25,
        ],
        'hospital' => [
            'id_column' => 'nearest_hospital_id',
            'distance_column' => 'distance_to_hospital_m',
            'label' => 'hospital',
            'cap_m' => 5000,
            'weight' => 0.25,
        ],
        'pharmacy' => [
            'id_column' => 'nearest_pharmacy_id',
            'distance_column' => 'distance_to_pharmacy_m',
            'label' => 'pharmacy',
            'cap_m' => 2500,
            'weight' => 0.20,
        ],
        'market' => [
            'id_column' => 'nearest_market_id',
            'distance_column' => 'distance_to_market_m',
            'label' => 'market',
            'cap_m' => 2500,
            'weight' => 0.15,
        ],
        'barangay_hall' => [
            'id_column' => 'nearest_barangay_hall_id',
            'distance_column' => 'distance_to_barangay_hall_m',
            'label' => 'barangay hall',
            'cap_m' => 2000,
            'weight' => 0.15,
        ],
    ];

    public function handle(): int
    {
        $facilities = $this->activeFacilitiesByCategory();
        $seniors = $this->seniorQuery()->get();

        if ($seniors->isEmpty()) {
            $this->warn('No active seniors with valid stored coordinates matched the selected filters.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $processed = 0;
        $roadScored = 0;

        // Preload cached road-network routes for these seniors so the score can use
        // real travel distance (falling back to straight-line where uncached).
        $routesBySenior = $this->roadRoutesBySenior($seniors->pluck('id')->all());

        $this->info(sprintf(
            '%s GIS proximity for %d senior(s). Uses cached ORS road distance where available, else straight-line.',
            $dryRun ? 'Previewing' : 'Scoring',
            $seniors->count()
        ));

        foreach ($seniors as $senior) {
            $payload = $this->scoreSenior($senior, $facilities, $routesBySenior[$senior->id] ?? collect());
            $scorePercent = round(($payload['accessibility_score'] ?? 0) * 100, 2);
            if (($payload['_road_components'] ?? 0) > 0) {
                $roadScored++;
            }
            unset($payload['_road_components']);

            if (! $dryRun) {
                SeniorAccessibilityMetric::updateOrCreate(
                    ['senior_citizen_id' => $senior->id],
                    $payload
                );
            }

            $processed++;
            $this->line(sprintf(
                '#%d %s %s: GIS proximity score %s/100',
                $senior->id,
                $senior->osca_id ?: 'NO-OSCA-ID',
                $senior->barangay ?: 'Unknown barangay',
                number_format($scorePercent, 2)
            ));
        }

        $this->info($dryRun
            ? "Dry run complete. {$processed} senior(s) calculated; no database rows were written."
            : "GIS proximity scoring complete. {$processed} senior accessibility metric row(s) saved."
        );
        $this->line("Scored with at least one road-network distance: {$roadScored}/{$processed} (rest used straight-line where routes aren't cached yet).");

        return self::SUCCESS;
    }

    /**
     * Cached, coordinate-fresh road routes grouped by senior, keyed by facility id.
     * Origin freshness (route computed from the senior's current pin) is enforced
     * here; destination freshness is checked per facility in scoreSenior().
     *
     * @return array<int, \Illuminate\Support\Collection>
     */
    private function roadRoutesBySenior(array $seniorIds): array
    {
        if (empty($seniorIds)) {
            return [];
        }

        return SeniorFacilityRouteDistance::query()
            ->whereIn('senior_citizen_id', $seniorIds)
            ->whereNotNull('route_distance_m')
            ->get()
            ->groupBy('senior_citizen_id')
            ->map(fn ($rows) => $rows->keyBy('facility_id'))
            ->all();
    }

    /** Two stored coordinate values refer to the same point (1e-6 tolerance). */
    private function coordinatesMatch(mixed $stored, float $current): bool
    {
        return $stored !== null && abs((float) $stored - $current) <= 0.000001;
    }

    private function seniorQuery()
    {
        return SeniorCitizen::active()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->when($this->option('senior-id'), fn ($query, $id) => $query->whereKey($id))
            ->when($this->option('barangay'), fn ($query, $barangay) => $query->where('barangay', $barangay))
            ->orderBy('barangay')
            ->orderBy('id');
    }

    private function activeFacilitiesByCategory(): array
    {
        $facilities = Facility::query()
            ->where('is_active', DB::raw('true'))
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        return [
            'health_center' => $this->matchingFacilities($facilities, ['health center', 'rural health', 'rhu']),
            'hospital' => $this->matchingFacilities($facilities, ['hospital']),
            'pharmacy' => $this->matchingFacilities($facilities, ['pharmacy', 'botika', 'drugstore', 'drug store']),
            'market' => $this->matchingFacilities($facilities, ['market', 'public market']),
            'barangay_hall' => $this->matchingFacilities($facilities, ['barangay hall']),
        ];
    }

    private function matchingFacilities(Collection $facilities, array $needles): Collection
    {
        return $facilities->filter(function (Facility $facility) use ($needles) {
            $type = strtolower((string) $facility->type);
            $name = strtolower((string) $facility->name);

            foreach ($needles as $needle) {
                if (str_contains($type, $needle) || str_contains($name, $needle)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    private function scoreSenior(SeniorCitizen $senior, array $facilitiesByCategory, $routes): array
    {
        $payload = [
            'calculated_at' => now(),
        ];
        $totalWeight = (float) array_sum(array_column(self::CATEGORY_CONFIG, 'weight'));
        $weightedTotal = 0.0;
        $availableWeight = 0.0;
        $roadComponents = 0;

        foreach (self::CATEGORY_CONFIG as $category => $config) {
            $nearest = $this->nearestFacility($senior, $facilitiesByCategory[$category] ?? collect());
            $facility = $nearest['facility'];

            // distance_to_* columns keep their meaning (straight-line to nearest);
            // the SCORE prefers cached road-network distance, falling back to it.
            $payload[$config['id_column']] = $facility?->id;
            $payload[$config['distance_column']] = $nearest['distance'];

            $road = $this->freshRoadDistance($routes, $facility, $senior);
            $straight = $nearest['distance'];

            // Trust a cached road distance only when its detour over the
            // straight-line distance is plausible; reject centroid-snapping
            // artifacts and fall back to straight-line for those.
            $useRoad = $road !== null
                && $straight !== null && $straight > 0
                && ($road / $straight) <= self::MAX_TRUSTED_DETOUR;
            $scoringDistance = $useRoad ? $road : $straight;

            if ($scoringDistance !== null) {
                // Caps are calibrated for straight-line distance; only widen them
                // to road scale when this component is actually scored on road-network
                // distance, so straight-line fallbacks keep their original calibration.
                $cap = $useRoad
                    ? $config['cap_m'] * self::ROAD_DETOUR_FACTOR
                    : $config['cap_m'];
                $component = max(0, 1 - ($scoringDistance / $cap));
                $weightedTotal += $component * $config['weight'];
                $availableWeight += $config['weight'];

                if ($useRoad) {
                    $roadComponents++;
                }
            }
        }

        $payload['accessibility_score'] = $availableWeight > 0
            ? round($weightedTotal / $totalWeight, 4)
            : null;
        $payload['_road_components'] = $roadComponents;

        return $payload;
    }

    /**
     * Cached road-network distance to a facility, but only if the route's stored
     * endpoints still match the senior's and facility's current coordinates.
     */
    private function freshRoadDistance($routes, ?Facility $facility, SeniorCitizen $senior): ?float
    {
        if (! $facility || $routes->isEmpty()) {
            return null;
        }

        $route = $routes->get($facility->id);
        if (! $route || $route->route_distance_m === null) {
            return null;
        }

        $fresh = $this->coordinatesMatch($route->origin_latitude, (float) $senior->latitude)
            && $this->coordinatesMatch($route->origin_longitude, (float) $senior->longitude)
            && $this->coordinatesMatch($route->destination_latitude, (float) $facility->latitude)
            && $this->coordinatesMatch($route->destination_longitude, (float) $facility->longitude);

        return $fresh ? (float) $route->route_distance_m : null;
    }

    private function nearestFacility(SeniorCitizen $senior, Collection $facilities): array
    {
        $nearestFacility = null;
        $nearestDistance = null;
        $seniorLat = (float) $senior->latitude;
        $seniorLng = (float) $senior->longitude;

        foreach ($facilities as $facility) {
            $distance = $this->haversineMeters(
                $seniorLat,
                $seniorLng,
                (float) $facility->latitude,
                (float) $facility->longitude
            );

            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearestFacility = $facility;
                $nearestDistance = $distance;
            }
        }

        return [
            'facility' => $nearestFacility,
            'distance' => $nearestDistance !== null ? round($nearestDistance, 2) : null,
        ];
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusM = 6371000;
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;

        return $earthRadiusM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
