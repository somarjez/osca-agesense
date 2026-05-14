<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\SeniorCitizen;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class GisApiController extends Controller
{
    public function seniors(): JsonResponse
    {
        $seniors = SeniorCitizen::active()
            ->with('latestMlResult')
            ->orderBy('id')
            ->get(['id', 'osca_id', 'barangay', 'date_of_birth', 'latitude', 'longitude']);

        if ($seniors->isEmpty()) {
            return $this->geoJsonResponse(
                $this->sampleSeniorFeatures(),
                'sample',
                'Sample generalized GIS data loaded for prototype testing.',
                [
                    'placement' => 'generalized_sample_points',
                    'total' => count($this->sampleSeniorFeatures()),
                ]
            );
        }

        $features = $seniors->map(function (SeniorCitizen $senior) {
            $latestResult = $senior->latestMlResult;
            [$latitude, $longitude] = $this->coordinatesForSenior($senior);

            return [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$longitude, $latitude],
                ],
                'properties' => [
                    'anonymized_id' => $senior->osca_id ?: 'SEN-' . str_pad((string) $senior->id, 4, '0', STR_PAD_LEFT),
                    'barangay' => $senior->barangay ?: 'Unknown',
                    'age' => $senior->age,
                    'risk_level' => $latestResult?->overall_risk_level
                        ? ucfirst(strtolower($latestResult->overall_risk_level))
                        : 'Unknown',
                    'cluster' => $latestResult?->cluster_named_id
                        ? 'Group ' . $latestResult->cluster_named_id
                        : 'Unassigned',
                    'composite_risk' => $latestResult?->composite_risk,
                ],
            ];
        })->values()->all();

        return $this->geoJsonResponse(
            $features,
            'database',
            'Database-backed generalized GIS data loaded for prototype testing.',
            [
                'placement' => 'stored_coordinates_and_generalized_barangay_fallback',
                'total' => count($features),
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

    private function coordinatesForSenior(SeniorCitizen $senior): array
    {
        if ($senior->latitude !== null && $senior->longitude !== null) {
            return [(float) $senior->latitude, (float) $senior->longitude];
        }

        return $this->generalizedCoordinatesForSenior($senior);
    }

    private function generalizedCoordinatesForSenior(SeniorCitizen $senior): array
    {
        $anchor = $this->barangayAnchors()[$senior->barangay] ?? [14.2708, 121.4560];
        $seed = sprintf('%s|%s|%s', $senior->id, $senior->osca_id, $senior->barangay);
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

    private function boundaryResponse(string $path, string $label): JsonResponse
    {
        if (!Storage::disk('local')->exists($path)) {
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

        if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'FeatureCollection' || !isset($decoded['features']) || !is_array($decoded['features'])) {
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
        $value = hexdec($hex) / 0xffffffff;

        return ($value * 2 - 1) * $spread;
    }

    private function barangayAnchors(): array
    {
        return [
            'Anibong' => [14.2782, 121.4588],
            'Biñan' => [14.2757, 121.4506],
            'BiÃ±an' => [14.2757, 121.4506],
            'Buboy' => [14.2667, 121.4602],
            'Calusiche' => [14.2629, 121.4524],
            'Cabanbanan' => [14.2685, 121.4477],
            'Dingin' => [14.2738, 121.4621],
            'Lambac' => [14.2688, 121.4591],
            'Layugan' => [14.2712, 121.4495],
            'Magdapio' => [14.2748, 121.4562],
            'Maulawin' => [14.2741, 121.4628],
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
                ],
            ],
        ];
    }
}
