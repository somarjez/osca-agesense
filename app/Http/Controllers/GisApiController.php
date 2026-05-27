<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\SeniorCitizen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class GisApiController extends Controller
{
    private ?array $barangayBoundaryFeatures = null;

    public function seniors(Request $request): JsonResponse
    {
        $seniors = SeniorCitizen::active()
            ->with(['latestMlResult', 'latestAccessibilityMetric'])
            ->orderBy('id')
            ->get([
                'id',
                'osca_id',
                'first_name',
                'middle_name',
                'last_name',
                'name_extension',
                'barangay',
                'latitude',
                'longitude',
                'location_source',
                'location_accuracy',
            ]);

        $groups = $this->groupSeniorsByBarangay($seniors);
        $features = [];
        $matchedSeniorCount = 0;

        foreach ($seniors as $senior) {
            $boundaryFeature = $this->barangayBoundaryFeature((string) $senior->barangay);

            if (!$boundaryFeature) {
                continue;
            }

            $barangay = $this->boundaryFeatureName($boundaryFeature);
            $normalized = $this->normalizeBarangayName($barangay);
            $stats = $groups[$normalized] ?? $this->emptyBarangayStats($barangay);
            $coordinates = $this->coordinatesForSenior($senior);
            $point = [$coordinates[0], $coordinates[1]];
            $locationStatus = $coordinates[2];

            if (!$point) {
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
                ? 'Group ' . $latestResult->cluster_named_id
                : ($latestResult?->cluster_name ?: 'Unassigned');
            $accessibilityScore = $accessibilityMetric?->accessibility_score !== null
                ? (float) $accessibilityMetric->accessibility_score
                : null;
            $accessibilityScorePercent = $this->accessibilityScorePercent($accessibilityScore);

            $matchedSeniorCount++;

            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$point[1], $point[0]],
                ],
                'properties' => [
                    'senior_id' => $senior->id,
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
                    'gis_proximity_score' => $accessibilityScorePercent,
                    'accessibility_score' => $accessibilityScore,
                    'accessibility_status' => $this->accessibilityStatus($accessibilityScorePercent),
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

        $unmatchedSeniorCount = max(0, $seniors->count() - $matchedSeniorCount);

        return $this->geoJsonResponse(
            $features,
            'database',
            'Database-backed senior GIS records loaded as generalized barangay-level points.',
            [
                'placement' => 'generalized_senior_points_by_barangay',
                'total' => $seniors->count(),
                'metadata' => [
                    'barangay_count' => count($groups),
                    'matched_senior_count' => $matchedSeniorCount,
                    'unmatched_senior_count' => $unmatchedSeniorCount,
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
            ->get(['name', 'type', 'barangay', 'latitude', 'longitude', 'source'])
            ->map(function (Facility $facility) {
                return [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [(float) $facility->longitude, (float) $facility->latitude],
                    ],
                    'properties' => [
                        'name' => $facility->name,
                        'type' => $facility->type,
                        'barangay' => $facility->barangay,
                        'source' => $facility->source,
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

    public function routeDistance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin_lat' => ['required', 'numeric', 'between:-90,90'],
            'origin_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $apiKey = env('OPENROUTESERVICE_API_KEY');

        if (!$apiKey) {
            return response()->json([
                'message' => 'OpenRouteService API key is not configured.',
            ], 503);
        }

        $response = Http::withHeaders([
            'Authorization' => $apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])
            ->connectTimeout(5)
            ->timeout(12)
            ->post('https://api.openrouteservice.org/v2/directions/driving-car/json', [
                'coordinates' => [
                    [(float) $validated['origin_lng'], (float) $validated['origin_lat']],
                    [(float) $validated['destination_lng'], (float) $validated['destination_lat']],
                ],
                'instructions' => false,
            ]);

        if (!$response->successful()) {
            return response()->json([
                'message' => 'OpenRouteService route request failed.',
                'status' => $response->status(),
            ], 502);
        }

        $summary = $response->json('routes.0.summary');

        if (!is_array($summary) || !isset($summary['distance'])) {
            return response()->json([
                'message' => 'OpenRouteService returned no usable route.',
            ], 502);
        }

        return response()->json([
            'provider' => 'openrouteservice',
            'distance' => round((float) $summary['distance'], 2),
            'duration' => isset($summary['duration']) ? round((float) $summary['duration'], 2) : null,
        ]);
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
                ? 'Group ' . $latestResult->cluster_named_id
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
        if (!$counts) {
            return $fallback;
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function accessibilityStatus(?float $score): string
    {
        if ($score === null) {
            return 'No accessibility score available';
        }

        if ($score >= 75) {
            return 'Good';
        }

        if ($score >= 50) {
            return 'Moderate';
        }

        return 'Needs attention';
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

        $path = 'gis/boundaries/pagsanjan_barangays.geojson';
        if (!Storage::disk('local')->exists($path)) {
            return $this->barangayBoundaryFeatures = [];
        }

        $decoded = json_decode(Storage::disk('local')->get($path), true);
        if (!is_array($decoded) || !isset($decoded['features']) || !is_array($decoded['features'])) {
            return $this->barangayBoundaryFeatures = [];
        }

        return $this->barangayBoundaryFeatures = $decoded['features'];
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
        $point = $this->deterministicPointInsideBoundary($feature, 'barangay-centroid|' . $barangay);

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
        if (!$rings) {
            return null;
        }

        $bounds = $this->coordinateBounds($rings);
        if (!$bounds) {
            return null;
        }

        [$minLng, $minLat, $maxLng, $maxLat] = $bounds;

        for ($attempt = 0; $attempt < 120; $attempt++) {
            $hash = md5($seed . '|' . $attempt);
            $lngRatio = hexdec(substr($hash, 0, 8)) / 0xffffffff;
            $latRatio = hexdec(substr($hash, 8, 8)) / 0xffffffff;
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
                if (!is_array($polygon) || !isset($polygon[0]) || !is_array($polygon[0])) {
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
                if (!is_array($coordinate) || count($coordinate) < 2) {
                    continue;
                }

                $lngValues[] = (float) $coordinate[0];
                $latValues[] = (float) $coordinate[1];
            }
        }

        if (!$lngValues || !$latValues) {
            return null;
        }

        return [min($lngValues), min($latValues), max($lngValues), max($latValues)];
    }

    private function pointInsideRings(array $point, array $rings): bool
    {
        if (!$rings || !$this->pointInsideRing($point, $rings[0])) {
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
            if (!is_array($ring[$i]) || !is_array($ring[$j]) || count($ring[$i]) < 2 || count($ring[$j]) < 2) {
                continue;
            }

            $xi = (float) $ring[$i][0];
            $yi = (float) $ring[$i][1];
            $xj = (float) $ring[$j][0];
            $yj = (float) $ring[$j][1];

            $intersects = (($yi > $y) !== ($yj > $y))
                && ($x < (($xj - $xi) * ($y - $yi)) / (($yj - $yi) ?: PHP_FLOAT_EPSILON) + $xi);

            if ($intersects) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    private function ringSignedArea(array $ring): float
    {
        $area = 0.0;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            if (!is_array($ring[$i]) || !is_array($ring[$j]) || count($ring[$i]) < 2 || count($ring[$j]) < 2) {
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
            if (!is_array($coordinate) || count($coordinate) < 2) {
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

    private function nearestFacilityDistance(mixed $metric): ?float
    {
        if (!$metric) {
            return null;
        }

        $distances = [
            $metric->distance_to_health_center_m,
            $metric->distance_to_barangay_hall_m,
            $metric->distance_to_market_m,
        ];

        $validDistances = array_values(array_filter(
            $distances,
            fn ($distance) => $distance !== null && is_numeric($distance)
        ));

        if (!$validDistances) {
            return null;
        }

        return round((float) min($validDistances), 2);
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
