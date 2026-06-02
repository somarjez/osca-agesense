<?php

namespace App\Console\Commands;

use App\Models\SeniorCitizen;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GeocodeSeniors extends Command
{
    protected $signature = 'gis:geocode
        {--dry-run : Preview changes without saving}
        {--barangay= : Process only one barangay}
        {--force : Rebuild only non-verified generated coordinates}
        {--limit= : Limit records processed for testing}';

    protected $description = 'Assign privacy-safe barangay-level approximate coordinates to seniors missing GIS coordinates.';

    private const MUNICIPAL_CENTER = [14.2708, 121.4560];

    private ?array $barangayFeatures = null;

    private ?array $municipalFeatures = null;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $barangay = $this->option('barangay');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $query = SeniorCitizen::query()
            ->orderBy('id')
            ->select([
                'id',
                'osca_id',
                'barangay',
                'latitude',
                'longitude',
                'location_source',
                'location_accuracy',
                'location_verified_at',
                'status',
            ]);

        if ($barangay) {
            $query->where('barangay', $barangay);
        }

        if ($limit) {
            $query->limit($limit);
        }

        $stats = [
            'checked' => 0,
            'updated' => 0,
            'skipped_verified' => 0,
            'skipped_existing' => 0,
            'skipped_missing_barangay_data' => 0,
            'municipal_fallback' => 0,
        ];
        $invalidBarangays = [];

        $this->info('GIS barangay-level geocoding');
        $this->line('Dry run: '.($dryRun ? 'yes' : 'no'));
        $this->line('Force generated coordinates: '.($force ? 'yes' : 'no'));
        if ($barangay) {
            $this->line("Barangay filter: {$barangay}");
        }
        if ($limit) {
            $this->line("Limit: {$limit}");
        }
        $this->newLine();

        $processSeniors = function ($seniors) use ($dryRun, $force, &$stats, &$invalidBarangays): void {
            foreach ($seniors as $senior) {
                $stats['checked']++;

                if ($this->isVerifiedCoordinate($senior)) {
                    $stats['skipped_verified']++;

                    continue;
                }

                $hasValidCoordinates = $this->hasValidCoordinates($senior->latitude, $senior->longitude);
                if ($hasValidCoordinates && ! $force) {
                    $stats['skipped_existing']++;

                    continue;
                }

                if ($hasValidCoordinates && $force && ! $this->isGeneratedCoordinate($senior)) {
                    $stats['skipped_existing']++;

                    continue;
                }

                $result = $this->coordinatesForSenior($senior);
                if (! $result) {
                    $stats['skipped_missing_barangay_data']++;
                    $invalidBarangays[$senior->barangay ?: '(blank)'] = true;

                    continue;
                }

                if ($result['used_municipal_fallback']) {
                    $stats['municipal_fallback']++;
                    $invalidBarangays[$senior->barangay ?: '(blank)'] = true;
                }

                $stats['updated']++;

                if (! $dryRun) {
                    $senior->forceFill([
                        'latitude' => $result['latitude'],
                        'longitude' => $result['longitude'],
                        'location_source' => $result['source'],
                        'location_accuracy' => $result['accuracy'],
                        'location_verified_at' => null,
                    ])->saveQuietly();
                }
            }
        };

        if ($limit) {
            $processSeniors($query->limit($limit)->get());
        } else {
            $query->chunkById(200, $processSeniors);
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total seniors checked', $stats['checked']],
                [$dryRun ? 'Seniors that would be updated' : 'Seniors updated', $stats['updated']],
                ['Skipped because already verified', $stats['skipped_verified']],
                ['Skipped because existing coordinates are present', $stats['skipped_existing']],
                ['Skipped because barangay polygon/centroid is missing', $stats['skipped_missing_barangay_data']],
                ['Used municipal fallback center', $stats['municipal_fallback']],
                ['Invalid barangay names found', count($invalidBarangays)],
                ['Dry run', $dryRun ? 'yes' : 'no'],
            ]
        );

        if ($invalidBarangays) {
            $this->warn('Invalid or missing barangay data: '.implode(', ', array_keys($invalidBarangays)));
        }

        if (! $dryRun) {
            Storage::disk('local')->put('gis/geocode_status.json', json_encode([
                'last_run_at' => now()->toIso8601String(),
                'checked' => $stats['checked'],
                'updated' => $stats['updated'],
                'skipped_verified' => $stats['skipped_verified'],
                'skipped_existing' => $stats['skipped_existing'],
                'skipped_missing_barangay_data' => $stats['skipped_missing_barangay_data'],
                'municipal_fallback' => $stats['municipal_fallback'],
                'invalid_barangays' => array_keys($invalidBarangays),
            ], JSON_PRETTY_PRINT));
        }

        $this->line('Generated coordinates are barangay-level approximations only. No records were marked verified.');

        return self::SUCCESS;
    }

    private function coordinatesForSenior(SeniorCitizen $senior): ?array
    {
        $seed = sprintf('%s|%s|%s', $senior->id, $senior->osca_id, $senior->barangay);
        $feature = $this->barangayFeature((string) $senior->barangay);

        if ($feature) {
            $point = $this->deterministicPointInsideFeature($feature, $seed)
                ?? $this->representativePoint($feature)
                ?? $this->anchorPoint((string) $senior->barangay);

            if ($point && $this->pointInsideFeature([$point[1], $point[0]], $feature) && $this->pointInsidePagsanjan($point)) {
                return [
                    'latitude' => round($point[0], 7),
                    'longitude' => round($point[1], 7),
                    'source' => 'barangay_generalized',
                    'accuracy' => 'barangay_level',
                    'used_municipal_fallback' => false,
                ];
            }
        }

        $anchor = $this->anchorPoint((string) $senior->barangay);
        if ($anchor && $this->pointInsidePagsanjan($anchor)) {
            return [
                'latitude' => round($anchor[0], 7),
                'longitude' => round($anchor[1], 7),
                'source' => 'barangay_centroid',
                'accuracy' => 'approximate',
                'used_municipal_fallback' => false,
            ];
        }

        if (! $senior->barangay) {
            return [
                'latitude' => self::MUNICIPAL_CENTER[0],
                'longitude' => self::MUNICIPAL_CENTER[1],
                'source' => 'barangay_centroid',
                'accuracy' => 'approximate',
                'used_municipal_fallback' => true,
            ];
        }

        return null;
    }

    private function isVerifiedCoordinate(SeniorCitizen $senior): bool
    {
        $source = strtolower((string) $senior->location_source);
        $accuracy = strtolower((string) $senior->location_accuracy);

        return in_array($source, ['manual_pin', 'gps_capture'], true)
            || str_contains($accuracy, 'verified')
            || str_contains($accuracy, 'manual');
    }

    private function isGeneratedCoordinate(SeniorCitizen $senior): bool
    {
        $source = strtolower((string) $senior->location_source);
        $accuracy = strtolower((string) $senior->location_accuracy);

        return in_array($source, ['barangay_generalized', 'barangay_centroid', 'generalized_barangay_fallback'], true)
            || str_contains($source, 'approximate')
            || str_contains($source, 'sample')
            || str_contains($source, 'prototype')
            || in_array($accuracy, ['barangay_level', 'approximate', 'generalized'], true)
            || str_contains($accuracy, 'approximate')
            || str_contains($accuracy, 'generalized');
    }

    private function hasValidCoordinates(mixed $latitude, mixed $longitude): bool
    {
        if ($latitude === null || $longitude === null || $latitude === '' || $longitude === '') {
            return false;
        }

        $lat = filter_var($latitude, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($longitude, FILTER_VALIDATE_FLOAT);

        if ($lat === false || $lng === false) {
            return false;
        }

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return false;
        }

        return abs((float) $lat) >= 0.000001 && abs((float) $lng) >= 0.000001;
    }

    private function barangayFeature(string $barangay): ?array
    {
        $needle = $this->normalizeBarangayName($barangay);

        foreach ($this->barangayFeatures() as $feature) {
            $name = $this->featureName($feature);
            if ($name && $this->normalizeBarangayName($name) === $needle) {
                return $feature;
            }
        }

        return null;
    }

    private function featureName(array $feature): ?string
    {
        $properties = $feature['properties'] ?? [];

        return $properties['name']
            ?? $properties['NAME']
            ?? $properties['barangay']
            ?? $properties['BARANGAY']
            ?? $properties['brgy_name']
            ?? $properties['BRGY_NAME']
            ?? $properties['ADM4_EN']
            ?? $properties['adm4_en']
            ?? null;
    }

    private function barangayFeatures(): array
    {
        if ($this->barangayFeatures !== null) {
            return $this->barangayFeatures;
        }

        return $this->barangayFeatures = $this->geoJsonFeatures('gis/boundaries/pagsanjan_barangays.geojson');
    }

    private function municipalFeatures(): array
    {
        if ($this->municipalFeatures !== null) {
            return $this->municipalFeatures;
        }

        return $this->municipalFeatures = $this->geoJsonFeatures('gis/boundaries/pagsanjan_boundary.geojson');
    }

    private function geoJsonFeatures(string $path): array
    {
        if (! Storage::disk('local')->exists($path)) {
            return [];
        }

        $decoded = json_decode(Storage::disk('local')->get($path), true);

        return is_array($decoded) && isset($decoded['features']) && is_array($decoded['features'])
            ? $decoded['features']
            : [];
    }

    private function deterministicPointInsideFeature(array $feature, string $seed): ?array
    {
        $rings = $this->polygonRings($feature);
        $bounds = $this->coordinateBounds($rings);

        if (! $rings || ! $bounds) {
            return null;
        }

        [$minLng, $minLat, $maxLng, $maxLat] = $bounds;

        for ($attempt = 0; $attempt < 160; $attempt++) {
            $hash = md5($seed.'|barangay-geocode|'.$attempt);
            $lngRatio = hexdec(substr($hash, 0, 8)) / 0xFFFFFFFF;
            $latRatio = hexdec(substr($hash, 8, 8)) / 0xFFFFFFFF;
            $lng = $minLng + (($maxLng - $minLng) * $lngRatio);
            $lat = $minLat + (($maxLat - $minLat) * $latRatio);
            $point = [$lng, $lat];

            if ($this->pointInsideRings($point, $rings) && $this->pointInsidePagsanjan([$lat, $lng])) {
                return [$lat, $lng];
            }
        }

        return null;
    }

    private function representativePoint(array $feature): ?array
    {
        $rings = $this->polygonRings($feature);
        if (! $rings || ! isset($rings[0])) {
            return null;
        }

        $point = $this->ringAveragePoint($rings[0]);
        if ($point && $this->pointInsideRings($point, $rings)) {
            return [$point[1], $point[0]];
        }

        return null;
    }

    private function anchorPoint(string $barangay): ?array
    {
        return $this->barangayAnchors()[$barangay]
            ?? $this->barangayAnchors()[$this->normalizeBarangayName($barangay)]
            ?? null;
    }

    private function pointInsidePagsanjan(array $latLng): bool
    {
        $features = $this->municipalFeatures();
        if (! $features) {
            return true;
        }

        $point = [(float) $latLng[1], (float) $latLng[0]];

        foreach ($features as $feature) {
            if ($this->pointInsideFeature($point, $feature)) {
                return true;
            }
        }

        return false;
    }

    private function pointInsideFeature(array $point, array $feature): bool
    {
        $rings = $this->polygonRings($feature);

        return $rings ? $this->pointInsideRings($point, $rings) : false;
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

        return $lngValues && $latValues
            ? [min($lngValues), min($latValues), max($lngValues), max($latValues)]
            : null;
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

    private function normalizeBarangayName(string $name): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
        $normalized = str_replace('(pob.)', '(poblacion)', $normalized);
        $normalized = preg_replace('/barangay i\s*\(pob\.\)/', 'barangay i (poblacion)', $normalized) ?? $normalized;
        $normalized = preg_replace('/barangay ii\s*\(pob\.\)/', 'barangay ii (poblacion)', $normalized) ?? $normalized;

        return $normalized;
    }

    private function barangayAnchors(): array
    {
        return [
            'Anibong' => [14.2782, 121.4588],
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
            'barangay i (poblacion)' => [14.2719, 121.4551],
            'Barangay II (Poblacion)' => [14.2704, 121.4567],
            'barangay ii (poblacion)' => [14.2704, 121.4567],
            'Sabang' => [14.2752, 121.4529],
            'Sampaloc' => [14.2674, 121.4632],
            'San Isidro' => [14.2639, 121.4583],
        ];
    }
}
