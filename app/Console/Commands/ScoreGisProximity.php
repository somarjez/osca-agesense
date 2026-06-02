<?php

namespace App\Console\Commands;

use App\Models\Facility;
use App\Models\SeniorAccessibilityMetric;
use App\Models\SeniorCitizen;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class ScoreGisProximity extends Command
{
    protected $signature = 'gis:score-proximity
        {--dry-run : Calculate and display scores without saving}
        {--senior-id= : Limit scoring to a single senior_citizens.id}
        {--barangay= : Limit scoring to seniors assigned to one barangay}';

    protected $description = 'Calculate GIS proximity scores from senior coordinates to nearby facilities.';

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

        $this->info(sprintf(
            '%s GIS proximity for %d senior(s). Model retraining is required before gis_proximity_score is used as an ML feature.',
            $dryRun ? 'Previewing' : 'Scoring',
            $seniors->count()
        ));

        foreach ($seniors as $senior) {
            $payload = $this->scoreSenior($senior, $facilities);
            $scorePercent = round(($payload['accessibility_score'] ?? 0) * 100, 2);

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

        return self::SUCCESS;
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
            ->where('is_active', true)
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

    private function scoreSenior(SeniorCitizen $senior, array $facilitiesByCategory): array
    {
        $payload = [
            'calculated_at' => now(),
        ];
        $totalWeight = (float) array_sum(array_column(self::CATEGORY_CONFIG, 'weight'));
        $weightedTotal = 0.0;
        $availableWeight = 0.0;

        foreach (self::CATEGORY_CONFIG as $category => $config) {
            $nearest = $this->nearestFacility($senior, $facilitiesByCategory[$category] ?? collect());
            $payload[$config['id_column']] = $nearest['facility']?->id;
            $payload[$config['distance_column']] = $nearest['distance'];

            if ($nearest['distance'] !== null) {
                $component = max(0, 1 - ($nearest['distance'] / $config['cap_m']));
                $weightedTotal += $component * $config['weight'];
                $availableWeight += $config['weight'];
            }
        }

        $payload['accessibility_score'] = $availableWeight > 0
            ? round($weightedTotal / $totalWeight, 4)
            : null;

        return $payload;
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
