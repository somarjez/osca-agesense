<?php

namespace App\Support;

use App\Models\SeniorCitizen;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Single source of truth for "how precise a coordinate is a caller allowed to
 * see". Two callers share this: GisApiController (the map GeoJSON feed) and
 * SeniorCitizenController (the profile's Location & Accessibility panel).
 *
 * `resolve()` is the only entry point. When `$fullPrecision` is false (viewer
 * role), the real stored coordinate — even a perfectly valid, field-verified
 * GPS pin — is never returned: the caller always gets the deterministic
 * barangay-generalized point, tagged `generalized`. This is a privacy
 * boundary, not a data-quality fallback, so it intentionally overrides
 * whatever `hasValidCoordinates()` would otherwise say.
 *
 * The generalization itself is deterministic per senior (seeded by id +
 * osca_id + barangay) so the same senior always lands on the same fuzzed
 * point — repeat requests don't jitter the pin around on screen.
 */
class CoordinatePrivacy
{
    private ?array $barangayBoundaryFeatures = null;

    /**
     * Resolve the coordinate a caller is allowed to see for this senior.
     *
     * @return array{0: float, 1: float, 2: string} [lat, lng, status] where
     *                                              status is 'verified', 'imported', or 'generalized'.
     */
    public function resolve(SeniorCitizen $senior, bool $fullPrecision): array
    {
        if ($fullPrecision && $this->hasValidCoordinates($senior->latitude, $senior->longitude)) {
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

    /**
     * Duplicated (small, pure) from GisApiController rather than shared, matching
     * this codebase's existing pattern (GeocodeSeniors also keeps its own copy) —
     * GisApiController::seniors()/groupSeniorsByBarangay() use their own copy
     * directly for barangay-boundary matching that has nothing to do with
     * coordinate privacy.
     */
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

    private function hashToOffset(string $hex, float $spread): float
    {
        $value = hexdec($hex) / 0xFFFFFFFF;

        return ($value * 2 - 1) * $spread;
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
}
