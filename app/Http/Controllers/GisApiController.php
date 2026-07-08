<?php

namespace App\Http\Controllers;

use App\Http\Requests\RouteDistanceRequest;
use App\Models\Facility;
use App\Models\SeniorCitizen;
use App\Models\SeniorFacilityRouteDistance;
use App\Models\SeniorFacilityRouteFailure;
use App\Support\AccessibilityBand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GisApiController extends Controller
{
    private ?array $barangayBoundaryFeatures = null;

    public function seniors(Request $request): JsonResponse
    {
        $barangayFilter = $request->query('barangay');
        $cacheKey = ($barangayFilter && $barangayFilter !== 'all')
            ? 'gis.seniors_geojson.'.md5($barangayFilter)
            : 'gis.seniors_geojson';

        $payload = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($barangayFilter) {
            $query = SeniorCitizen::active()
                ->with(['latestMlResult', 'latestAccessibilityMetric'])
                ->orderBy('id');

            if ($barangayFilter && $barangayFilter !== 'all') {
                $query->where('barangay', $barangayFilter);
            }

            $seniors = $query->get([
                'id', 'uuid', 'osca_id', 'first_name', 'middle_name', 'last_name',
                'name_extension', 'barangay', 'date_of_birth', 'latitude',
                'longitude', 'location_source', 'location_accuracy',
            ]);

            $boundaryMap = collect($this->barangayBoundaryFeatures())
                ->keyBy(fn ($f) => $this->normalizeBarangayName(
                    (string) ($f['properties']['name']
                        ?? $f['properties']['NAME']
                        ?? $f['properties']['barangay']
                        ?? $f['properties']['BARANGAY']
                        ?? $f['properties']['brgy_name']
                        ?? $f['properties']['BRGY_NAME']
                        ?? $f['properties']['ADM4_EN']
                        ?? $f['properties']['adm4_en']
                        ?? '')
                ));

            $groups = $this->groupSeniorsByBarangay($seniors);
            $accessibilityFacilities = $this->accessibilityDistanceFacilities();
            $features = [];
            $matchedSeniorCount = 0;

            foreach ($seniors as $senior) {
                $normalizedBarangay = $this->normalizeBarangayName((string) $senior->barangay);
                $boundaryFeature = $boundaryMap[$normalizedBarangay] ?? null;

                if (! $boundaryFeature) {
                    continue;
                }

                $barangay = $this->boundaryFeatureName($boundaryFeature);
                $normalized = $this->normalizeBarangayName($barangay);
                $stats = $groups[$normalized] ?? $this->emptyBarangayStats($barangay);
                $coordinates = $this->coordinatesForSenior($senior);
                $point = [$coordinates[0], $coordinates[1]];
                $locationStatus = $coordinates[2];

                if (! is_finite($point[0]) || ! is_finite($point[1])
                    || ($point[0] === 0.0 && $point[1] === 0.0)) {
                    continue;
                }

                $latestResult = $senior->latestMlResult;
                $accessibilityMetric = $senior->latestAccessibilityMetric;
                $riskScore = $latestResult?->composite_risk ?? $latestResult?->rule_composite;
                $risk = $latestResult?->overall_risk_level
                    ? ucfirst(strtolower($latestResult->overall_risk_level))
                    : 'Unknown';
                $clusterId = $latestResult?->cluster_named_id ?? $latestResult?->cluster_id;
                $cluster = $latestResult?->cluster_named_id
                    ? 'Group '.$latestResult->cluster_named_id
                    : ($latestResult?->cluster_name ?: 'Unassigned');
                $accessibilityScore = $accessibilityMetric?->accessibility_score !== null
                    ? (float) $accessibilityMetric->accessibility_score
                    : null;
                $accessibilityScorePercent = $this->accessibilityScorePercent($accessibilityScore);
                $accessibilityConcern = $this->accessibilityConcernPayload($senior, $accessibilityMetric, $accessibilityScore, $accessibilityFacilities);

                $matchedSeniorCount++;

                $features[] = [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [$point[1], $point[0]],
                    ],
                    'properties' => [
                        'anonymized_id' => $senior->osca_id ?: 'SEN-'.str_pad((string) $senior->id, 4, '0', STR_PAD_LEFT),
                        'age' => $senior->age,
                        'composite_risk' => $latestResult?->composite_risk,
                        'senior_id' => $senior->id,
                        'senior_uuid' => $senior->uuid,
                        'senior_name' => $senior->full_name,
                        'osca_id' => $senior->osca_id,
                        'barangay' => $barangay,
                        'senior_count' => 1,
                        'total_seniors' => $stats['count'],
                        'high_risk_count' => strtoupper($risk) === 'HIGH' ? 1 : 0,
                        'barangay_total_seniors' => $stats['count'],
                        'barangay_accessibility_status' => $this->accessibilityStatus($stats['accessibility_score_percent']),
                        'risk_score' => $riskScore !== null ? round((float) $riskScore, 4) : null,
                        'risk_level' => $risk,
                        'cluster_id' => $clusterId,
                        'cluster_label' => $cluster,
                        'cluster' => $cluster,
                        'health_group_id' => $clusterId,
                        'health_group' => $cluster,
                        'gis_proximity_score' => $accessibilityScorePercent,
                        'accessibility_score' => $accessibilityScore,
                        'accessibility_distance_m' => $accessibilityConcern['distance_m'],
                        'nearest_facility_distance_m' => $accessibilityConcern['distance_m'],
                        'accessibility_concern_score' => $accessibilityConcern['score'],
                        'accessibility_surface_weight' => $accessibilityConcern['score'],
                        'accessibility_level' => $accessibilityConcern['level'],
                        'accessibility_group' => $accessibilityConcern['level'],
                        'accessibility_status' => $this->accessibilityStatus($accessibilityScorePercent),
                        'coordinate_mode' => $locationStatus,
                        'location_source' => $locationStatus === 'generalized'
                            ? 'generalized_barangay_point'
                            : ($senior->location_source ?: $locationStatus),
                        'location_accuracy' => $locationStatus === 'generalized'
                            ? 'barangay_level_generalized'
                            : ($senior->location_accuracy ?: 'stored_coordinate'),
                        'location_status' => $locationStatus,
                        'is_generalized_senior_point' => $locationStatus === 'generalized',
                    ],
                ];
            }

            return [
                'features' => $features,
                'total' => $seniors->count(),
                'barangay_count' => count($groups),
                'matched_senior_count' => $matchedSeniorCount,
                'unmatched_senior_count' => max(0, $seniors->count() - $matchedSeniorCount),
            ];
        });

        return $this->geoJsonResponse(
            $payload['features'],
            'database',
            'Database-backed senior GIS records loaded as generalized barangay-level points.',
            [
                'placement' => 'generalized_senior_points_by_barangay',
                'total' => $payload['total'],
                'metadata' => [
                    'barangay_count' => $payload['barangay_count'],
                    'matched_senior_count' => $payload['matched_senior_count'],
                    'unmatched_senior_count' => $payload['unmatched_senior_count'],
                    'aggregation' => 'per_senior_generalized_by_barangay',
                ],
            ]
        );
    }

    public function facilities(): JsonResponse
    {
        $features = Facility::query()
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'barangay', 'latitude', 'longitude', 'source', 'osm_id'])
            ->map(function (Facility $facility) {
                return [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [(float) $facility->longitude, (float) $facility->latitude],
                    ],
                    'properties' => [
                        'facility_id' => $facility->id,
                        'name' => $facility->name,
                        'type' => $facility->type,
                        'barangay' => $facility->barangay,
                        'source' => $facility->source,
                        'osm_id' => $facility->osm_id,
                    ],
                ];
            })
            ->values()
            ->all();

        return $this->geoJsonResponse(
            $features,
            'database',
            'Database-backed facility GIS data loaded.',
            [
                'placement' => 'public_facility_coordinates',
                'total' => count($features),
            ]
        );
    }

    public function pagsanjanBoundary(): JsonResponse
    {
        return $this->boundaryResponse(
            'gis/boundaries/pagsanjan_boundary.geojson',
            'Pagsanjan municipal boundary'
        );
    }

    public function barangayBoundaries(): JsonResponse
    {
        return $this->boundaryResponse(
            'gis/boundaries/pagsanjan_barangays.geojson',
            'Pagsanjan barangay boundaries'
        );
    }

    public function routeDistance(RouteDistanceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $cachedRoute = $this->cachedRouteDistance($validated);
        if ($cachedRoute) {
            return response()->json($cachedRoute);
        }

        $cachedFailure = $this->cachedRouteFailure($validated);
        if ($cachedFailure) {
            return response()->json($cachedFailure, 502);
        }

        $apiKey = env('OPENROUTESERVICE_API_KEY');

        if (! $apiKey) {
            return response()->json([
                'message' => 'OpenRouteService API key is not configured.',
            ], 503);
        }

        try {
            $verify = $this->openRouteServiceVerifyOption();
        } catch (\Throwable $exception) {
            Log::warning('GIS OpenRouteService SSL configuration error: '.$exception->getMessage());

            return response()->json([
                'message' => $exception->getMessage(),
            ], 503);
        }

        $http = Http::withHeaders([
            'Authorization' => $apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])
            ->withOptions(['verify' => $verify])
            ->connectTimeout((int) config('services.openrouteservice.connect_timeout', 3))
            ->timeout((int) config('services.openrouteservice.timeout', 5))
            ->retry(
                (int) config('services.openrouteservice.retry_times', 0),
                (int) config('services.openrouteservice.retry_sleep_ms', 500)
            );

        try {
            $baseUrl = rtrim((string) config('services.openrouteservice.base_url', 'https://api.heigit.org'), '/');
            $response = $http->post("{$baseUrl}/v2/directions/driving-car/json", [
                'coordinates' => [
                    [(float) $validated['origin_lng'], (float) $validated['origin_lat']],
                    [(float) $validated['destination_lng'], (float) $validated['destination_lat']],
                ],
                'radiuses' => $this->openRouteServiceRadiuses(),
                'instructions' => false,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('GIS OpenRouteService connection error: '.$exception->getMessage());

            return response()->json([
                'message' => $this->openRouteServiceFailureMessage($exception),
            ], 502);
        }

        if (! $response->successful()) {
            $orsMessage = $this->openRouteServiceErrorMessage($response->json());
            Log::warning('GIS OpenRouteService route request failed.', [
                'status' => $response->status(),
                'message' => $orsMessage,
            ]);
            $this->storeRouteFailure($validated, $response->status(), $orsMessage);

            return response()->json([
                'message' => 'OpenRouteService route request failed.',
                'status' => $response->status(),
                'error' => $orsMessage,
            ], 502);
        }

        $summary = $response->json('routes.0.summary');

        if (! is_array($summary) || ! isset($summary['distance'])) {
            $this->storeRouteFailure($validated, 502, 'OpenRouteService returned no usable route.');

            return response()->json([
                'message' => 'OpenRouteService returned no usable route.',
            ], 502);
        }

        $route = [
            'provider' => 'openrouteservice',
            'distance' => round((float) $summary['distance'], 2),
            'duration' => isset($summary['duration']) ? round((float) $summary['duration'], 2) : null,
        ];

        $this->storeRouteDistance($validated, $route);

        return response()->json($route);
    }

    private function cachedRouteFailure(array $validated): ?array
    {
        $seniorId = $validated['senior_id'] ?? null;
        $facilityId = $validated['facility_id'] ?? null;

        if ($seniorId === null || $facilityId === null) {
            return null;
        }

        $failure = SeniorFacilityRouteFailure::query()
            ->where('senior_citizen_id', $seniorId)
            ->where('facility_id', $facilityId)
            ->first();

        if (! $failure || ! $this->routeCacheCoordinatesMatch($validated, $failure)) {
            return null;
        }

        return [
            'message' => 'OpenRouteService route is unavailable for this senior/service pair.',
            'provider' => $failure->provider ?: 'openrouteservice',
            'status' => $failure->status_code,
            'error' => $failure->error_message,
            'cached' => true,
            'unavailable' => true,
        ];
    }

    private function cachedRouteDistance(array $validated): ?array
    {
        $seniorId = $validated['senior_id'] ?? null;
        $facilityId = $validated['facility_id'] ?? null;

        if ($seniorId === null || $facilityId === null) {
            return null;
        }

        $route = SeniorFacilityRouteDistance::query()
            ->where('senior_citizen_id', $seniorId)
            ->where('facility_id', $facilityId)
            ->first();

        if (! $route) {
            return null;
        }

        if (! $this->routeCacheCoordinatesMatch($validated, $route)) {
            return null;
        }

        return [
            'provider' => $route->provider ?: 'cached',
            'distance' => (float) $route->route_distance_m,
            'duration' => $route->route_duration_s !== null ? (float) $route->route_duration_s : null,
            'cached' => true,
        ];
    }

    private function storeRouteDistance(array $validated, array $route): void
    {
        $seniorId = $validated['senior_id'] ?? null;
        $facilityId = $validated['facility_id'] ?? null;

        if ($seniorId === null || $facilityId === null) {
            return;
        }

        SeniorFacilityRouteDistance::query()->updateOrCreate(
            [
                'senior_citizen_id' => $seniorId,
                'facility_id' => $facilityId,
            ],
            [
                'origin_latitude' => round((float) $validated['origin_lat'], 7),
                'origin_longitude' => round((float) $validated['origin_lng'], 7),
                'destination_latitude' => round((float) $validated['destination_lat'], 7),
                'destination_longitude' => round((float) $validated['destination_lng'], 7),
                'route_distance_m' => round((float) $route['distance'], 2),
                'route_duration_s' => isset($route['duration']) ? round((float) $route['duration'], 2) : null,
                'provider' => $route['provider'] ?? 'openrouteservice',
                'calculated_at' => now(),
            ]
        );

        SeniorFacilityRouteFailure::query()
            ->where('senior_citizen_id', $seniorId)
            ->where('facility_id', $facilityId)
            ->delete();
    }

    private function storeRouteFailure(array $validated, ?int $statusCode, string $message): void
    {
        $seniorId = $validated['senior_id'] ?? null;
        $facilityId = $validated['facility_id'] ?? null;

        if ($seniorId === null || $facilityId === null) {
            return;
        }

        if ($statusCode === 429) {
            return;
        }

        SeniorFacilityRouteFailure::query()->updateOrCreate(
            [
                'senior_citizen_id' => $seniorId,
                'facility_id' => $facilityId,
            ],
            [
                'origin_latitude' => round((float) $validated['origin_lat'], 7),
                'origin_longitude' => round((float) $validated['origin_lng'], 7),
                'destination_latitude' => round((float) $validated['destination_lat'], 7),
                'destination_longitude' => round((float) $validated['destination_lng'], 7),
                'provider' => 'openrouteservice',
                'status_code' => $statusCode,
                'error_message' => mb_strimwidth($message, 0, 500, '...'),
                'failed_at' => now(),
            ]
        );
    }

    private function routeCacheCoordinatesMatch(array $validated, object $route): bool
    {
        $pairs = [
            [(float) $validated['origin_lat'], (float) $route->origin_latitude],
            [(float) $validated['origin_lng'], (float) $route->origin_longitude],
            [(float) $validated['destination_lat'], (float) $route->destination_latitude],
            [(float) $validated['destination_lng'], (float) $route->destination_longitude],
        ];

        foreach ($pairs as [$current, $cached]) {
            if (abs($current - $cached) > 0.000001) {
                return false;
            }
        }

        return true;
    }

    private function openRouteServiceVerifyOption(): bool|string
    {
        $caBundle = trim((string) env('OPENROUTESERVICE_CA_BUNDLE', ''));
        if ($caBundle !== '') {
            if (! is_file($caBundle) || ! is_readable($caBundle)) {
                throw new \RuntimeException("OPENROUTESERVICE_CA_BUNDLE is set but the file does not exist or is not readable: {$caBundle}");
            }

            return $caBundle;
        }

        if (filter_var(env('OPENROUTESERVICE_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN) === false) {
            Log::warning('OPENROUTESERVICE_VERIFY_SSL=false is enabled for GIS OpenRouteService requests. Use this only for local development.');

            return false;
        }

        return true;
    }

    private function openRouteServiceRadiuses(): array
    {
        $configuredRadius = (int) env('OPENROUTESERVICE_SNAP_RADIUS_METERS', -1);
        if ($configuredRadius === -1) {
            return [-1, -1];
        }

        $radius = max(350, min(5000, $configuredRadius));

        return [$radius, $radius];
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

    private function openRouteServiceFailureMessage(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'cURL error 60')) {
            return 'OpenRouteService SSL certificate verification failed. Set OPENROUTESERVICE_CA_BUNDLE to a readable cacert.pem file.';
        }

        if (str_contains($message, 'cURL error')) {
            return 'OpenRouteService connection failed: '.mb_strimwidth($message, 0, 180, '...');
        }

        return 'OpenRouteService route request could not be completed.';
    }

    private function geoJsonResponse(array $features, string $source, string $note, array $meta = []): JsonResponse
    {
        return response()->json(
            [
                'type' => 'FeatureCollection',
                'source' => $source,
                'placement' => $meta['placement'] ?? null,
                'total' => $meta['total'] ?? count($features),
                'note' => $note,
                'metadata' => $meta['metadata'] ?? null,
                'features' => $features,
            ],
            200,
            ['Content-Type' => 'application/geo+json; charset=UTF-8']
        );
    }

    private function groupSeniorsByBarangay($seniors): array
    {
        $groups = [];

        foreach ($seniors as $senior) {
            $barangay = (string) ($senior->barangay ?: 'Unknown');
            $key = $this->normalizeBarangayName($barangay);
            $groups[$key] ??= $this->emptyBarangayStats($barangay);

            $latestResult = $senior->latestMlResult;
            $accessibilityMetric = $senior->latestAccessibilityMetric;
            $risk = $latestResult?->overall_risk_level
                ? ucfirst(strtolower($latestResult->overall_risk_level))
                : 'Unknown';
            $cluster = $latestResult?->cluster_named_id
                ? 'Group '.$latestResult->cluster_named_id
                : 'Unassigned';
            $accessibilityScore = $accessibilityMetric?->accessibility_score !== null
                ? (float) $accessibilityMetric->accessibility_score
                : null;

            $groups[$key]['count']++;
            $groups[$key]['risk_counts'][$risk] = ($groups[$key]['risk_counts'][$risk] ?? 0) + 1;
            $groups[$key]['cluster_counts'][$cluster] = ($groups[$key]['cluster_counts'][$cluster] ?? 0) + 1;

            if (strtoupper($risk) === 'HIGH') {
                $groups[$key]['high_risk_count']++;
            }

            if ($accessibilityScore !== null) {
                $groups[$key]['accessibility_total'] += $accessibilityScore;
                $groups[$key]['accessibility_count']++;
            }
        }

        foreach ($groups as $key => $stats) {
            $avgAccessibility = $stats['accessibility_count'] > 0
                ? $stats['accessibility_total'] / $stats['accessibility_count']
                : null;

            $groups[$key]['dominant_risk'] = $this->dominantLabel($stats['risk_counts'], 'Unknown');
            $groups[$key]['dominant_cluster'] = $this->dominantLabel($stats['cluster_counts'], 'Unassigned');
            $groups[$key]['accessibility_score'] = $avgAccessibility !== null ? round($avgAccessibility, 4) : null;
            $groups[$key]['accessibility_score_percent'] = $this->accessibilityScorePercent($avgAccessibility);
        }

        return $groups;
    }

    private function emptyBarangayStats(string $barangay): array
    {
        return [
            'barangay' => $barangay,
            'count' => 0,
            'high_risk_count' => 0,
            'risk_counts' => [],
            'cluster_counts' => [],
            'dominant_risk' => 'Unknown',
            'dominant_cluster' => 'Unassigned',
            'accessibility_total' => 0.0,
            'accessibility_count' => 0,
            'accessibility_score' => null,
            'accessibility_score_percent' => null,
        ];
    }

    private function dominantLabel(array $counts, string $fallback): string
    {
        if (! $counts) {
            return $fallback;
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function accessibilityStatus(?float $score): string
    {
        $band = AccessibilityBand::classify($score);

        return $band['short'] ?? 'No accessibility score available';
    }

    private function coordinatesForSenior(SeniorCitizen $senior): array
    {
        if ($this->hasValidCoordinates($senior->latitude, $senior->longitude)) {
            $source = strtolower((string) $senior->location_source);
            $accuracy = strtolower((string) $senior->location_accuracy);
            $verifiedSources = ['manual_pin', 'gps_capture'];
            $isVerified = in_array($source, $verifiedSources, true)
                || str_contains($accuracy, 'verified')
                || str_contains($accuracy, 'manual');

            return [
                (float) $senior->latitude,
                (float) $senior->longitude,
                $isVerified ? 'verified' : 'imported',
            ];
        }

        return [...$this->generalizedCoordinatesForSenior($senior), 'generalized'];
    }

    private function hasValidCoordinates(mixed $latitude, mixed $longitude): bool
    {
        if ($latitude === null || $longitude === null) {
            return false;
        }

        $lat = filter_var($latitude, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($longitude, FILTER_VALIDATE_FLOAT);

        return $lat !== false
            && $lng !== false
            && $lat >= -90
            && $lat <= 90
            && $lng >= -180
            && $lng <= 180;
    }

    private function generalizedCoordinatesForSenior(SeniorCitizen $senior): array
    {
        $seed = sprintf('%s|%s|%s', $senior->id, $senior->osca_id, $senior->barangay);
        $boundaryFeature = $this->barangayBoundaryFeature((string) $senior->barangay);

        if ($boundaryFeature) {
            $point = $this->deterministicPointInsideBoundary($boundaryFeature, $seed);

            if ($point) {
                return $point;
            }
        }

        $anchor = $this->barangayAnchors()[$senior->barangay] ?? [14.2708, 121.4560];
        $hash = md5($seed);

        $latOffset = $this->hashToOffset(substr($hash, 0, 8), 0.0016);
        $lngOffset = $this->hashToOffset(substr($hash, 8, 8), 0.0018);

        // Generalize each point around a barangay anchor so the GIS view remains
        // useful without revealing exact home locations.
        return [
            round($anchor[0] + $latOffset, 7),
            round($anchor[1] + $lngOffset, 7),
        ];
    }

    private function barangayBoundaryFeature(string $barangay): ?array
    {
        $normalizedBarangay = $this->normalizeBarangayName($barangay);

        foreach ($this->barangayBoundaryFeatures() as $feature) {
            $properties = $feature['properties'] ?? [];
            $label = $properties['name']
                ?? $properties['NAME']
                ?? $properties['barangay']
                ?? $properties['BARANGAY']
                ?? $properties['brgy_name']
                ?? $properties['BRGY_NAME']
                ?? $properties['ADM4_EN']
                ?? $properties['adm4_en']
                ?? null;

            if ($this->normalizeBarangayName((string) $label) === $normalizedBarangay) {
                return $feature;
            }
        }

        return null;
    }

    private function boundaryFeatureName(array $feature): string
    {
        $properties = $feature['properties'] ?? [];

        return (string) (
            $properties['name']
            ?? $properties['NAME']
            ?? $properties['barangay']
            ?? $properties['BARANGAY']
            ?? $properties['brgy_name']
            ?? $properties['BRGY_NAME']
            ?? $properties['ADM4_EN']
            ?? $properties['adm4_en']
            ?? 'Barangay boundary'
        );
    }

    private function barangayBoundaryFeatures(): array
    {
        if ($this->barangayBoundaryFeatures !== null) {
            return $this->barangayBoundaryFeatures;
        }

        $this->barangayBoundaryFeatures = Cache::remember(
            'gis.barangay_boundary_features',
            now()->addHours(24),
            function () {
                $path = 'gis/boundaries/pagsanjan_barangays.geojson';
                if (! Storage::disk('local')->exists($path)) {
                    return [];
                }

                $decoded = json_decode(Storage::disk('local')->get($path), true);
                if (! is_array($decoded) || ! isset($decoded['features']) || ! is_array($decoded['features'])) {
                    return [];
                }

                return $decoded['features'];
            }
        );

        return $this->barangayBoundaryFeatures;
    }

    private function normalizeBarangayName(string $name): string
    {
        $normalized = strtolower(trim($name));
        $normalized = str_replace(['biã±an', 'biãƒâ±an'], 'binan', $normalized);
        $normalized = preg_replace('/\bbrgy\.?\s*/', '', $normalized) ?? $normalized;
        $normalized = str_replace('(pob.)', '(poblacion)', $normalized);
        $normalized = preg_replace('/barangay i\s*\(pob\.\)/', 'barangay i (poblacion)', $normalized) ?? $normalized;
        $normalized = preg_replace('/barangay ii\s*\(pob\.\)/', 'barangay ii (poblacion)', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function representativePointForBoundary(array $feature, string $barangay): ?array
    {
        $point = $this->deterministicPointInsideBoundary($feature, 'barangay-centroid|'.$barangay);

        if ($point) {
            return $point;
        }

        $rings = $this->polygonRings($feature);
        $center = $rings ? $this->ringAveragePoint($rings[0]) : null;

        return $center ? [round($center[1], 7), round($center[0], 7)] : null;
    }

    private function deterministicPointInsideBoundary(array $feature, string $seed): ?array
    {
        $rings = $this->polygonRings($feature);
        if (! $rings) {
            return null;
        }

        $bounds = $this->coordinateBounds($rings);
        if (! $bounds) {
            return null;
        }

        [$minLng, $minLat, $maxLng, $maxLat] = $bounds;

        for ($attempt = 0; $attempt < 120; $attempt++) {
            $hash = md5($seed.'|'.$attempt);
            $lngRatio = hexdec(substr($hash, 0, 8)) / 0xFFFFFFFF;
            $latRatio = hexdec(substr($hash, 8, 8)) / 0xFFFFFFFF;
            $lng = $minLng + (($maxLng - $minLng) * $lngRatio);
            $lat = $minLat + (($maxLat - $minLat) * $latRatio);

            if ($this->pointInsideRings([$lng, $lat], $rings)) {
                return [round($lat, 7), round($lng, 7)];
            }
        }

        $center = $this->ringAveragePoint($rings[0]);
        if ($center && $this->pointInsideRings($center, $rings)) {
            return [round($center[1], 7), round($center[0], 7)];
        }

        foreach ($rings[0] as $coordinate) {
            if (is_array($coordinate) && count($coordinate) >= 2) {
                return [round((float) $coordinate[1], 7), round((float) $coordinate[0], 7)];
            }
        }

        return null;
    }

    private function polygonRings(array $feature): array
    {
        $geometry = $feature['geometry'] ?? null;
        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;

        if ($type === 'Polygon' && is_array($coordinates)) {
            return $coordinates;
        }

        if ($type === 'MultiPolygon' && is_array($coordinates)) {
            $largestPolygon = [];
            $largestArea = -1.0;

            foreach ($coordinates as $polygon) {
                if (! is_array($polygon) || ! isset($polygon[0]) || ! is_array($polygon[0])) {
                    continue;
                }

                $area = abs($this->ringSignedArea($polygon[0]));
                if ($area > $largestArea) {
                    $largestPolygon = $polygon;
                    $largestArea = $area;
                }
            }

            return $largestPolygon;
        }

        return [];
    }

    private function coordinateBounds(array $rings): ?array
    {
        $lngValues = [];
        $latValues = [];

        foreach ($rings as $ring) {
            foreach ($ring as $coordinate) {
                if (! is_array($coordinate) || count($coordinate) < 2) {
                    continue;
                }

                $lngValues[] = (float) $coordinate[0];
                $latValues[] = (float) $coordinate[1];
            }
        }

        if (! $lngValues || ! $latValues) {
            return null;
        }

        return [min($lngValues), min($latValues), max($lngValues), max($latValues)];
    }

    private function pointInsideRings(array $point, array $rings): bool
    {
        if (! $rings || ! $this->pointInsideRing($point, $rings[0])) {
            return false;
        }

        foreach (array_slice($rings, 1) as $hole) {
            if ($this->pointInsideRing($point, $hole)) {
                return false;
            }
        }

        return true;
    }

    private function pointInsideRing(array $point, array $ring): bool
    {
        $x = (float) $point[0];
        $y = (float) $point[1];
        $inside = false;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            if (! is_array($ring[$i]) || ! is_array($ring[$j]) || count($ring[$i]) < 2 || count($ring[$j]) < 2) {
                continue;
            }

            $xi = (float) $ring[$i][0];
            $yi = (float) $ring[$i][1];
            $xj = (float) $ring[$j][0];
            $yj = (float) $ring[$j][1];

            $intersects = (($yi > $y) !== ($yj > $y))
                && ($x < (($xj - $xi) * ($y - $yi)) / (($yj - $yi) ?: PHP_FLOAT_EPSILON) + $xi);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private function ringSignedArea(array $ring): float
    {
        $area = 0.0;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            if (! is_array($ring[$i]) || ! is_array($ring[$j]) || count($ring[$i]) < 2 || count($ring[$j]) < 2) {
                continue;
            }

            $area += ((float) $ring[$j][0] * (float) $ring[$i][1]) - ((float) $ring[$i][0] * (float) $ring[$j][1]);
        }

        return $area / 2;
    }

    private function ringAveragePoint(array $ring): ?array
    {
        $lng = 0.0;
        $lat = 0.0;
        $count = 0;

        foreach ($ring as $coordinate) {
            if (! is_array($coordinate) || count($coordinate) < 2) {
                continue;
            }

            $lng += (float) $coordinate[0];
            $lat += (float) $coordinate[1];
            $count++;
        }

        return $count > 0 ? [$lng / $count, $lat / $count] : null;
    }

    private function boundaryResponse(string $path, string $label): JsonResponse
    {
        if (! Storage::disk('local')->exists($path)) {
            return $this->geoJsonResponse(
                [],
                'file',
                "{$label} file is not available yet.",
                [
                    'placement' => 'optional_boundary_file',
                    'total' => 0,
                    'metadata' => [
                        'status' => 'file_missing',
                        'path' => $path,
                    ],
                ]
            );
        }

        $decoded = json_decode(Storage::disk('local')->get($path), true);

        if (! is_array($decoded) || ($decoded['type'] ?? null) !== 'FeatureCollection' || ! isset($decoded['features']) || ! is_array($decoded['features'])) {
            return $this->geoJsonResponse(
                [],
                'file',
                "{$label} file could not be parsed as GeoJSON.",
                [
                    'placement' => 'optional_boundary_file',
                    'total' => 0,
                    'metadata' => [
                        'status' => 'invalid_geojson',
                        'path' => $path,
                    ],
                ]
            );
        }

        return response()->json(
            array_merge($decoded, [
                'source' => 'file',
                'placement' => 'optional_boundary_file',
                'total' => count($decoded['features']),
                'note' => "{$label} loaded from local storage.",
                'metadata' => [
                    'status' => 'loaded',
                    'path' => $path,
                ],
            ]),
            200,
            ['Content-Type' => 'application/geo+json; charset=UTF-8']
        );
    }

    private function hashToOffset(string $hex, float $spread): float
    {
        $value = hexdec($hex) / 0xFFFFFFFF;

        return ($value * 2 - 1) * $spread;
    }

    private function accessibilityScorePercent(mixed $score): ?float
    {
        if ($score === null) {
            return null;
        }

        $value = (float) $score;
        if ($value <= 1.0) {
            $value *= 100;
        }

        return round(max(0, min(100, $value)), 2);
    }

    private function accessibilityDistanceFacilities()
    {
        return Facility::query()
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['id', 'name', 'type', 'latitude', 'longitude'])
            ->filter(fn (Facility $facility) => $this->isAccessibilityDistanceFacility($facility))
            ->values();
    }

    private function isAccessibilityDistanceFacility(Facility $facility): bool
    {
        $text = strtolower(trim($facility->type.' '.$facility->name));

        foreach (['health center', 'rural health', 'rhu', 'hospital', 'pharmacy', 'botika', 'drugstore', 'drug store', 'market', 'public market', 'barangay hall', 'senior center'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Heatmap "concern" payload, unified onto the AccessibilityBand classification so
     * the surface tells the same story as the popups and the profile card.
     *   - score:      continuous heat weight (1 - accessibility_score); higher = poorer access.
     *   - level:      discrete band key (good/moderate/limited/priority) for legend + colour.
     *   - distance_m: nearest senior-relevant facility distance, kept for popups.
     */
    private function accessibilityConcernPayload(SeniorCitizen $senior, mixed $metric, ?float $accessibilityScore, $facilities): array
    {
        $distance = $this->nearestFacilityDistance($metric, $senior, $facilities);

        if ($accessibilityScore === null) {
            return [
                'distance_m' => $distance,
                'score' => null,
                'level' => null,
            ];
        }

        $band = AccessibilityBand::classify($accessibilityScore);

        return [
            'distance_m' => $distance,
            'score' => round(max(0.0, min(1.0, 1 - $accessibilityScore)), 4),
            'level' => $band['key'] ?? null,
        ];
    }

    private function nearestFacilityDistance(mixed $metric, ?SeniorCitizen $senior = null, $facilities = null): ?float
    {
        $distances = $metric
            ? [
                $metric->distance_to_health_center_m,
                $metric->distance_to_barangay_hall_m,
                $metric->distance_to_market_m,
                $metric->distance_to_hospital_m,
                $metric->distance_to_pharmacy_m,
            ]
            : [];

        $validDistances = array_values(array_filter(
            $distances,
            fn ($distance) => $distance !== null && is_numeric($distance)
        ));

        if ($validDistances) {
            return round((float) min($validDistances), 2);
        }

        if (! $senior || ! $facilities || $facilities->isEmpty()
            || $senior->latitude === null || $senior->longitude === null) {
            return null;
        }

        $seniorLat = (float) $senior->latitude;
        $seniorLng = (float) $senior->longitude;
        $nearest = null;

        foreach ($facilities as $facility) {
            $distance = $this->haversineMeters(
                $seniorLat,
                $seniorLng,
                (float) $facility->latitude,
                (float) $facility->longitude
            );

            $nearest = $nearest === null ? $distance : min($nearest, $distance);
        }

        return $nearest !== null ? round($nearest, 2) : null;
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

    private function barangayAnchors(): array
    {
        return [
            'Anibong' => [14.2782, 121.4588],
            'Biñan' => [14.2757, 121.4506],
            'Buboy' => [14.2667, 121.4602],
            'Calusiche' => [14.2629, 121.4524],
            'Cabanbanan' => [14.2685, 121.4477],
            'Dingin' => [14.2738, 121.4621],
            'Lambac' => [14.2688, 121.4591],
            'Layugan' => [14.2712, 121.4495],
            'Magdapio' => [14.2748, 121.4562],
            'Maulawin' => [14.2737, 121.4625],
            'Pinagsanjan' => [14.2657, 121.4512],
            'Barangay I (Poblacion)' => [14.2719, 121.4551],
            'Barangay II (Poblacion)' => [14.2704, 121.4567],
            'Sabang' => [14.2752, 121.4529],
            'Sampaloc' => [14.2674, 121.4632],
            'San Isidro' => [14.2639, 121.4583],
        ];
    }

    private function sampleSeniorFeatures(): array
    {
        return [
            [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [121.4550, 14.2730],
                ],
                'properties' => [
                    'anonymized_id' => 'SEN-001',
                    'barangay' => 'Barangay I (Poblacion)',
                    'age' => 68,
                    'risk_level' => 'Moderate',
                    'cluster' => 'Group 1',
                    'composite_risk' => 0.41,
                    'gis_proximity_score' => 74.0,
                    'accessibility_score' => 0.74,
                    'nearest_facility_distance_m' => 220.0,
                ],
            ],
            [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [121.4572, 14.2714],
                ],
                'properties' => [
                    'anonymized_id' => 'SEN-002',
                    'barangay' => 'Barangay II (Poblacion)',
                    'age' => 74,
                    'risk_level' => 'High',
                    'cluster' => 'Group 3',
                    'composite_risk' => 0.78,
                    'gis_proximity_score' => 45.0,
                    'accessibility_score' => 0.45,
                    'nearest_facility_distance_m' => 610.0,
                ],
            ],
            [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [121.4528, 14.2751],
                ],
                'properties' => [
                    'anonymized_id' => 'SEN-003',
                    'barangay' => 'Sabang',
                    'age' => 81,
                    'risk_level' => 'Low',
                    'cluster' => 'Group 2',
                    'composite_risk' => 0.26,
                    'gis_proximity_score' => 82.0,
                    'accessibility_score' => 0.82,
                    'nearest_facility_distance_m' => 180.0,
                ],
            ],
            [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [121.4603, 14.2686],
                ],
                'properties' => [
                    'anonymized_id' => 'SEN-004',
                    'barangay' => 'Lambac',
                    'age' => 77,
                    'risk_level' => 'High',
                    'cluster' => 'Group 3',
                    'composite_risk' => 0.82,
                    'gis_proximity_score' => 38.0,
                    'accessibility_score' => 0.38,
                    'nearest_facility_distance_m' => 740.0,
                ],
            ],
            [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [121.4507, 14.2665],
                ],
                'properties' => [
                    'anonymized_id' => 'SEN-005',
                    'barangay' => 'Pinagsanjan',
                    'age' => 72,
                    'risk_level' => 'Moderate',
                    'cluster' => 'Group 1',
                    'composite_risk' => 0.53,
                    'gis_proximity_score' => 62.0,
                    'accessibility_score' => 0.62,
                    'nearest_facility_distance_m' => 360.0,
                ],
            ],
            [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [121.4638, 14.2742],
                ],
                'properties' => [
                    'anonymized_id' => 'SEN-006',
                    'barangay' => 'Maulawin',
                    'age' => 85,
                    'risk_level' => 'Low',
                    'cluster' => 'Group 2',
                    'composite_risk' => 0.29,
                    'gis_proximity_score' => 52.0,
                    'accessibility_score' => 0.52,
                    'nearest_facility_distance_m' => 520.0,
                ],
            ],
        ];
    }
}
