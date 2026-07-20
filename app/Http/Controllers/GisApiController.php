<?php

namespace App\Http\Controllers;

use App\Http\Requests\RouteDistanceRequest;
use App\Models\Facility;
use App\Models\SeniorCitizen;
use App\Models\SeniorFacilityRouteDistance;
use App\Models\SeniorFacilityRouteFailure;
use App\Support\AccessibilityBand;
use App\Support\CoordinatePrivacy;
use App\Support\SeniorDataVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GisApiController extends Controller
{
    private ?array $barangayBoundaryFeatures = null;

    public function __construct(private CoordinatePrivacy $coordinatePrivacy) {}

    public function seniors(Request $request): JsonResponse
    {
        $barangayFilter = $request->query('barangay');
        $precisionMode = $this->precisionMode();
        $version = SeniorDataVersion::current();
        $cacheKey = ($barangayFilter && $barangayFilter !== 'all')
            ? 'gis.seniors_geojson.'.$precisionMode.'.'.$version.'.'.md5($barangayFilter)
            : 'gis.seniors_geojson.'.$precisionMode.'.'.$version;

        $payload = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($barangayFilter) {
            $query = SeniorCitizen::active()
                ->with(['latestMlResult', 'latestAccessibilityMetric'])
                ->orderBy('id');

            if ($barangayFilter && $barangayFilter !== 'all') {
                $query->where('barangay', $barangayFilter);
            }

            $seniors = $query->get([
                'id', 'uuid', 'osca_id', 'official_osca_id', 'first_name', 'middle_name', 'last_name',
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
                        'official_osca_id' => $senior->official_osca_id,
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
        $features = Facility::cachedActiveWithCoordinates()
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

        if ($this->shouldPersistRouteDistance($validated)) {
            $this->storeRouteDistance($validated, $route);
        }

        return response()->json($route);
    }

    /**
     * Guards the shared route-distance cache against corruption by a
     * non-full-precision caller. Viewers only ever see a barangay-generalized
     * origin point for a senior (see coordinatesForSenior()/fullPrecision()),
     * so a viewer who supplies a senior_id here has no legitimate reason to
     * also know that senior's real coordinates. If a senior_id is present and
     * the caller lacks full precision, only persist when the supplied origin
     * actually matches the senior's real stored coordinates — otherwise we'd
     * overwrite a previously-cached accurate route with one computed from an
     * arbitrary/generalized origin, destroying the cache and wasting external
     * API quota for every other viewer of that senior/facility pair. The live
     * distance is still computed and returned either way; only the cache
     * write is skipped.
     */
    private function shouldPersistRouteDistance(array $validated): bool
    {
        $seniorId = $validated['senior_id'] ?? null;

        if ($seniorId === null || $this->fullPrecision()) {
            return true;
        }

        $senior = SeniorCitizen::find($seniorId);

        if (! $senior) {
            return false;
        }

        return $this->coordinatesMatch($senior->latitude, (float) $validated['origin_lat'])
            && $this->coordinatesMatch($senior->longitude, (float) $validated['origin_lng']);
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
        return $this->coordinatesMatch($route->origin_latitude, (float) $validated['origin_lat'])
            && $this->coordinatesMatch($route->origin_longitude, (float) $validated['origin_lng'])
            && $this->coordinatesMatch($route->destination_latitude, (float) $validated['destination_lat'])
            && $this->coordinatesMatch($route->destination_longitude, (float) $validated['destination_lng']);
    }

    /**
     * Two stored coordinate values refer to the same point (within rounding).
     * Canonical 1e-6 tolerance for this codebase — mirrored by
     * SeniorCitizenController::coordinatesMatch() and
     * ScoreGisProximity::coordinatesMatch() for the same kind of
     * floating-point-tolerant lat/lng comparison.
     */
    private function coordinatesMatch(mixed $stored, float $current): bool
    {
        return $stored !== null && abs((float) $stored - $current) <= 0.000001;
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

    /**
     * Role-gated coordinate for a senior. Admin/encoder get exact-precision
     * behavior unchanged (verified/imported/generalized-for-missing-data);
     * viewer always gets the deterministic barangay-generalized point, even
     * when a real verified GPS pin exists. See CoordinatePrivacy::resolve().
     */
    private function coordinatesForSenior(SeniorCitizen $senior): array
    {
        return $this->coordinatePrivacy->resolve($senior, $this->fullPrecision());
    }

    /** Admin/encoder see exact coordinates; every other role gets generalized. */
    private function fullPrecision(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['admin', 'encoder']);
    }

    /** Cache-key dimension for coordinatesForSenior()'s role gate — see seniors(). */
    private function precisionMode(): string
    {
        return $this->fullPrecision() ? 'full' : 'generalized';
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
        return Facility::cachedActiveWithCoordinates()
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
