<?php

namespace App\Console\Commands;

use App\Http\Controllers\GisApiController;
use App\Models\SeniorFacilityRouteDistance;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CacheGisRouteDistances extends Command
{
    protected $signature = 'gis:cache-route-distances
        {--seniors= : Maximum number of senior GIS records to process}
        {--facilities=5 : Number of nearby senior-relevant facilities to cache per senior}
        {--max-requests=1500 : Maximum OpenRouteService requests to send in this run}
        {--stop-after-rate-limits=1 : Stop the run after this many rate-limit/API-limit errors}
        {--dry-run : Count route pairs that need OpenRouteService without sending requests}
        {--force : Recalculate and overwrite existing cached route distances}
        {--sleep-ms=2500 : Milliseconds to sleep after each OpenRouteService request}';

    protected $description = 'Precompute GIS road-network route distances for senior popup accessibility services.';

    private const FACILITY_PRIORITY = [
        ['health center', 'hospital', 'clinic', 'rural health'],
        ['pharmacy', 'drugstore', 'medicine'],
        ['senior center', 'senior citizens', 'osca'],
        ['barangay hall', 'municipal hall'],
        ['public market', 'market', 'transport hub', 'terminal'],
        ['church', 'chapel'],
    ];

    public function handle(): int
    {
        $apiKey = env('OPENROUTESERVICE_API_KEY');
        if (!$apiKey && !$this->option('dry-run')) {
            $this->error('OPENROUTESERVICE_API_KEY is not configured.');
            return self::FAILURE;
        }

        $controller = app(GisApiController::class);
        $seniorGeoJson = $controller->seniors(Request::create('/'))->getData(true);
        $facilityGeoJson = $controller->facilities()->getData(true);
        $seniorFeatures = $seniorGeoJson['features'] ?? [];
        $facilityFeatures = $facilityGeoJson['features'] ?? [];
        $seniorLimit = $this->option('seniors') ? (int) $this->option('seniors') : null;
        $facilityLimit = max(1, (int) $this->option('facilities'));
        $maxRequestsOption = $this->option('max-requests');
        $maxRequests = $maxRequestsOption === null || $maxRequestsOption === ''
            ? null
            : max(0, (int) $maxRequestsOption);
        $stopAfterRateLimits = max(0, (int) $this->option('stop-after-rate-limits'));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $orsVerify = true;

        if (!$dryRun) {
            try {
                $orsVerify = $this->openRouteServiceVerifyOption();
            } catch (\Throwable $exception) {
                $this->error($exception->getMessage());
                return self::FAILURE;
            }
        }

        if ($seniorLimit) {
            $seniorFeatures = array_slice($seniorFeatures, 0, $seniorLimit);
        }

        $bar = $this->output->createProgressBar(count($seniorFeatures));
        $totalPairsChecked = 0;
        $skippedCached = 0;
        $skippedInvalid = 0;
        $orsRequestsSent = 0;
        $cachedRoutes = 0;
        $failed = 0;
        $rateLimitOrApiErrors = 0;
        $hitRequestCap = false;

        $this->info($dryRun
            ? 'Dry run: counting route pairs only. No OpenRouteService requests will be sent.'
            : 'Caching GIS route distances using OpenRouteService.');

        if ($force) {
            $this->warn('Force mode enabled: existing cached route distances will be recalculated.');
        } else {
            $this->line('Existing cached route pairs will be skipped. New seniors/routes only will be requested.');
        }

        if (!$dryRun) {
            $this->line("Throttle delay: {$sleepMs} ms after each OpenRouteService request.");
            if ($maxRequests !== null) {
                $this->line("Request cap: this run will stop after {$maxRequests} OpenRouteService request(s).");
            }
            if ($stopAfterRateLimits > 0) {
                $this->line("Rate-limit guard: this run will stop after {$stopAfterRateLimits} rate-limit/API-limit error(s).");
            }
        }

        foreach ($seniorFeatures as $seniorFeature) {
            if ($hitRequestCap) {
                break;
            }

            $seniorId = $seniorFeature['properties']['senior_id'] ?? null;
            $origin = $this->featurePoint($seniorFeature);

            if (!$seniorId || !$origin) {
                $skippedInvalid++;
                $bar->advance();
                continue;
            }

            foreach ($this->candidateFacilities($origin, $facilityFeatures, $facilityLimit) as $candidate) {
                $totalPairsChecked++;
                $facilityId = $candidate['feature']['properties']['facility_id'] ?? null;
                $destination = $candidate['point'];

                if (!$facilityId) {
                    $skippedInvalid++;
                    continue;
                }

                $hasCachedRoute = SeniorFacilityRouteDistance::query()
                    ->where('senior_citizen_id', $seniorId)
                    ->where('facility_id', $facilityId)
                    ->exists();

                if ($hasCachedRoute && !$force) {
                    $skippedCached++;
                    continue;
                }

                if ($dryRun) {
                    continue;
                }

                if ($maxRequests !== null && $orsRequestsSent >= $maxRequests) {
                    $hitRequestCap = true;
                    break;
                }

                try {
                    $orsRequestsSent++;
                    $route = $this->openRouteServiceRoute($apiKey, $origin, $destination, $orsVerify);
                    SeniorFacilityRouteDistance::query()->updateOrCreate(
                        [
                            'senior_citizen_id' => $seniorId,
                            'facility_id' => $facilityId,
                        ],
                        [
                            'origin_latitude' => round($origin['lat'], 7),
                            'origin_longitude' => round($origin['lng'], 7),
                            'destination_latitude' => round($destination['lat'], 7),
                            'destination_longitude' => round($destination['lng'], 7),
                            'route_distance_m' => round($route['distance'], 2),
                            'route_duration_s' => isset($route['duration']) ? round($route['duration'], 2) : null,
                            'provider' => 'openrouteservice',
                            'calculated_at' => now(),
                        ]
                    );
                    $cachedRoutes++;
                } catch (\Throwable $exception) {
                    $failed++;
                    if ($this->isRateLimitOrApiError($exception)) {
                        $rateLimitOrApiErrors++;
                    }
                    if ($this->isOpenRouteServiceLimitError($exception)
                        && $stopAfterRateLimits > 0
                        && $rateLimitOrApiErrors >= $stopAfterRateLimits
                    ) {
                        $hitRequestCap = true;
                    }
                    $this->warn($this->failureMessage($exception));
                } finally {
                    if ($sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->line("Total pairs checked: {$totalPairsChecked}");
        $this->line("Skipped cached pairs: {$skippedCached}");
        $this->line("Skipped invalid pairs: {$skippedInvalid}");
        $this->line("Actual ORS requests sent: {$orsRequestsSent}");
        $this->line("Successfully cached routes: {$cachedRoutes}");
        $this->line("Failed requests: {$failed}");
        $this->line("Rate-limit/API errors: {$rateLimitOrApiErrors}");

        if ($dryRun) {
            $routesNeedingRequests = $force
                ? max(0, $totalPairsChecked - $skippedInvalid)
                : max(0, $totalPairsChecked - $skippedCached - $skippedInvalid);
            $this->info("Dry run complete. Route pairs needing ORS requests: {$routesNeedingRequests}.");
        } else {
            if ($hitRequestCap) {
                $this->warn('Stopped early because a request guard was reached. Run the command again later to continue caching missing routes.');
            }
            $this->info('Route-distance cache command complete.');
        }

        return self::SUCCESS;
    }

    private function featurePoint(array $feature): ?array
    {
        $coordinates = $feature['geometry']['coordinates'] ?? null;
        if (!is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }

        $lng = (float) $coordinates[0];
        $lat = (float) $coordinates[1];

        return is_finite($lat) && is_finite($lng) ? compact('lat', 'lng') : null;
    }

    private function candidateFacilities(array $origin, array $facilityFeatures, int $limit): array
    {
        $candidates = array_values(array_filter(array_map(function (array $feature) use ($origin) {
            $point = $this->featurePoint($feature);
            if (!$point) {
                return null;
            }

            return [
                'feature' => $feature,
                'point' => $point,
                'priority' => $this->facilityPriority($feature),
                'straight_distance' => $this->haversineMeters($origin, $point),
            ];
        }, $facilityFeatures)));

        $relevant = array_values(array_filter($candidates, fn (array $candidate) => $candidate['priority'] < count(self::FACILITY_PRIORITY)));
        $source = $relevant ?: $candidates;

        usort($source, fn (array $a, array $b) => $a['priority'] <=> $b['priority']
            ?: $a['straight_distance'] <=> $b['straight_distance']);

        return array_slice($source, 0, $limit);
    }

    private function facilityPriority(array $feature): int
    {
        $properties = $feature['properties'] ?? [];
        $text = strtolower(implode(' ', array_filter([
            $properties['name'] ?? null,
            $properties['type'] ?? null,
            $properties['barangay'] ?? null,
        ])));

        foreach (self::FACILITY_PRIORITY as $index => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $index;
                }
            }
        }

        return count(self::FACILITY_PRIORITY);
    }

    private function haversineMeters(array $a, array $b): float
    {
        $earthRadius = 6371000;
        $lat1 = deg2rad($a['lat']);
        $lat2 = deg2rad($b['lat']);
        $deltaLat = deg2rad($b['lat'] - $a['lat']);
        $deltaLng = deg2rad($b['lng'] - $a['lng']);
        $haversine = sin($deltaLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($deltaLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($haversine), sqrt(1 - $haversine));
    }

    private function openRouteServiceRoute(string $apiKey, array $origin, array $destination, bool|string $verify): array
    {
        $response = Http::withHeaders([
            'Authorization' => $apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])
            ->withOptions(['verify' => $verify])
            ->connectTimeout(5)
            ->timeout(12)
            ->post('https://api.openrouteservice.org/v2/directions/driving-car/json', [
                'coordinates' => [
                    [$origin['lng'], $origin['lat']],
                    [$destination['lng'], $destination['lat']],
                ],
                'instructions' => false,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                'OpenRouteService failed with HTTP ' . $response->status() . ': ' . $this->openRouteServiceErrorMessage($response->json()),
                $response->status()
            );
        }

        $summary = $response->json('routes.0.summary');
        if (!is_array($summary) || !isset($summary['distance'])) {
            throw new \RuntimeException('OpenRouteService returned no usable route.');
        }

        return [
            'distance' => (float) $summary['distance'],
            'duration' => isset($summary['duration']) ? (float) $summary['duration'] : null,
        ];
    }

    private function openRouteServiceVerifyOption(): bool|string
    {
        $caBundle = trim((string) env('OPENROUTESERVICE_CA_BUNDLE', ''));
        if ($caBundle !== '') {
            if (!is_file($caBundle) || !is_readable($caBundle)) {
                throw new \RuntimeException("OPENROUTESERVICE_CA_BUNDLE is set but the file does not exist or is not readable: {$caBundle}");
            }

            return $caBundle;
        }

        if (filter_var(env('OPENROUTESERVICE_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN) === false) {
            $this->warn('WARNING: OPENROUTESERVICE_VERIFY_SSL=false is enabled. Use this only for local development; production should use SSL verification or OPENROUTESERVICE_CA_BUNDLE.');
            return false;
        }

        return true;
    }

    private function openRouteServiceErrorMessage(mixed $payload): string
    {
        if (is_array($payload)) {
            $message = $payload['error']['message']
                ?? $payload['message']
                ?? $payload['error']
                ?? null;

            if (is_string($message) && trim($message) !== '') {
                return mb_strimwidth($message, 0, 180, '...');
            }
        }

        return 'No error message returned.';
    }

    private function failureMessage(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'cURL error 60')) {
            return 'OpenRouteService SSL certificate verification failed (cURL error 60). Set OPENROUTESERVICE_CA_BUNDLE to a readable cacert.pem file.';
        }

        if (str_contains($message, 'cURL error')) {
            return 'OpenRouteService connection failed: ' . mb_strimwidth($message, 0, 220, '...');
        }

        return 'OpenRouteService request failed: ' . mb_strimwidth($message, 0, 220, '...');
    }

    private function isRateLimitOrApiError(\Throwable $exception): bool
    {
        $code = (int) $exception->getCode();

        return $code === 429
            || ($code >= 400 && $code <= 599)
            || str_contains($exception->getMessage(), 'OpenRouteService failed');
    }

    private function isOpenRouteServiceLimitError(\Throwable $exception): bool
    {
        return (int) $exception->getCode() === 429
            || str_contains(strtolower($exception->getMessage()), 'rate limit');
    }
}
