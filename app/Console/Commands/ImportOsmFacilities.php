<?php

namespace App\Console\Commands;

use App\Models\Facility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ImportOsmFacilities extends Command
{
    protected $signature = 'facilities:import-osm
        {--dry-run : Preview changes without writing to database}
        {--force : Re-import facilities that already have an osm_id}
        {--no-supersede : Skip deactivating matched approximate facilities}';

    protected $description = 'Import facility data from OpenStreetMap Overpass API for Pagsanjan.';

    private const SUPERSEDE_RADIUS_METERS = 50;

    private ?array $barangayFeatures = null;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $noSupersede = (bool) $this->option('no-supersede');

        $this->info('Querying Overpass API for Pagsanjan amenities...');
        if ($dryRun) {
            $this->line('<fg=yellow>Dry run — no changes will be written.</>');
        }

        $elements = $this->queryOverpass();
        if ($elements === null) {
            return self::FAILURE;
        }

        $this->info('Fetched '.count($elements).' nodes/ways from OpenStreetMap.');
        $this->newLine();
        $this->line('Importing...');

        $stats = ['fetched' => count($elements), 'imported' => 0, 'updated' => 0, 'superseded' => 0, 'skipped' => 0];

        foreach ($elements as $element) {
            $data = $this->buildFacilityData($element);

            if ($data === null) {
                $stats['skipped']++;
                continue;
            }

            $osmId = $data['osm_id'];
            $existing = Facility::where('osm_id', $osmId)->first();

            if ($existing && ! $force) {
                $stats['skipped']++;
                continue;
            }

            $isNew = $existing === null;

            if (! $dryRun) {
                if ($isNew) {
                    Facility::create(array_merge($data, ['source' => 'openstreetmap', 'is_active' => true]));
                } else {
                    // --force: update name/type/coordinates/address only; never restore is_active
                    $existing->update(array_intersect_key($data, array_flip([
                        'name', 'type', 'address', 'latitude', 'longitude',
                    ])));
                }
            }

            if ($isNew) {
                $stats['imported']++;
                if (! $noSupersede && ! $dryRun) {
                    $stats['superseded'] += $this->supersedeApproximate($data);
                }
            } else {
                $stats['updated']++;
            }

            $this->line(sprintf('  %-38s  %-20s  %-26s  [%s]',
                mb_substr($data['name'], 0, 38),
                $data['type'],
                $data['barangay'] ?? '—',
                $isNew ? 'new' : 'updated'
            ));
        }

        $this->newLine();
        $this->line('Done.');
        $this->table(
            ['Fetched', 'Imported', 'Updated', 'Superseded', 'Skipped'],
            [[$stats['fetched'], $stats['imported'], $stats['updated'], $stats['superseded'], $stats['skipped']]]
        );

        return self::SUCCESS;
    }

    private function queryOverpass(): ?array
    {
        $url = config('services.overpass.url', 'https://overpass-api.de/api/interpreter');

        try {
            $response = Http::withHeaders(['User-Agent' => 'AgeSense-OSCA/1.0 (osca-agesense)'])
                ->timeout(40)
                ->retry(2, 3000)
                ->asForm()
                ->post($url, ['data' => $this->overpassQuery()]);

            if (! $response->successful()) {
                $this->error("Overpass API returned HTTP {$response->status()}. Aborting — no data changed.");
                return null;
            }

            return $response->json('elements', []);
        } catch (\Exception $e) {
            $this->error('Overpass API request failed: '.$e->getMessage());
            return null;
        }
    }

    private function overpassQuery(): string
    {
        $bbox = '14.255,121.435,14.290,121.475';

        return '[out:json][timeout:30];'
            .'(node["amenity"~"hospital|clinic|doctors|health_centre|nursing_home|pharmacy|place_of_worship|marketplace|community_centre|social_facility|townhall|bus_station|taxi"]('.$bbox.');'
            .'node["office"="government"]('.$bbox.');'
            .'node["shop"~"chemist|supermarket|convenience|general|market"]('.$bbox.');'
            .'node["highway"="bus_stop"]('.$bbox.');'
            .'way["amenity"~"hospital|marketplace|community_centre|townhall"]('.$bbox.');'
            .'way["shop"~"supermarket|market"]('.$bbox.');'
            .')->.results;.results out center tags;';
    }

    private function buildFacilityData(array $element): ?array
    {
        $elementType = $element['type'] ?? null;

        if ($elementType === 'node') {
            $lat = isset($element['lat']) ? (float) $element['lat'] : null;
            $lon = isset($element['lon']) ? (float) $element['lon'] : null;
        } elseif ($elementType === 'way') {
            $lat = isset($element['center']['lat']) ? (float) $element['center']['lat'] : null;
            $lon = isset($element['center']['lon']) ? (float) $element['center']['lon'] : null;
        } else {
            return null;
        }

        if ($lat === null || $lon === null || ! is_finite($lat) || ! is_finite($lon)) {
            return null;
        }

        $tags = $element['tags'] ?? [];
        $type = $this->mapOsmType($tags);

        if ($type === null) {
            return null;
        }

        $osmId = $elementType.':'.$element['id'];
        $barangay = $this->assignBarangay($lat, $lon);

        return [
            'osm_id'    => $osmId,
            'name'      => $this->resolveName($tags, $type, $barangay),
            'type'      => $type,
            'barangay'  => $barangay,
            'address'   => $this->resolveAddress($tags, $barangay),
            'latitude'  => round($lat, 7),
            'longitude' => round($lon, 7),
        ];
    }

    private function mapOsmType(array $tags): ?string
    {
        $amenity = $tags['amenity'] ?? null;
        $office  = $tags['office'] ?? null;
        $shop    = $tags['shop'] ?? null;
        $highway = $tags['highway'] ?? null;
        $name    = strtolower($tags['name'] ?? '');

        if ($amenity === 'hospital') return 'Hospital';
        if (in_array($amenity, ['clinic', 'doctors', 'health_centre', 'nursing_home'], true)) return 'Health Center';
        if ($amenity === 'pharmacy' || $shop === 'chemist') return 'Pharmacy';
        if ($amenity === 'place_of_worship') return 'Church';
        if ($amenity === 'marketplace' || $shop === 'market') return 'Public Market';
        if ($shop === 'supermarket') return 'Supermarket';
        if (in_array($shop, ['convenience', 'general'], true)) return 'Community Store';

        if ($amenity === 'townhall' || $office === 'government') {
            if (str_contains($name, 'municipal')) return 'Municipal Hall';
            return 'Barangay Hall';
        }

        if ($amenity === 'community_centre') {
            return str_contains($name, 'senior') ? 'Senior Center' : 'Community Store';
        }

        if ($amenity === 'social_facility') return 'Community Store';
        if ($amenity === 'bus_station') return 'Transport Hub';

        if ($highway === 'bus_stop' || $amenity === 'taxi') {
            return str_contains($name, 'jeepney') ? 'Jeepney Terminal' : 'Transport Hub';
        }

        return null;
    }

    private function resolveName(array $tags, string $type, ?string $barangay): string
    {
        return $tags['name']
            ?? $tags['name:en']
            ?? $tags['operator']
            ?? ($type.($barangay ? ' — '.$barangay : ''));
    }

    private function resolveAddress(array $tags, ?string $barangay): string
    {
        $parts = array_filter([
            $tags['addr:housenumber'] ?? null,
            $tags['addr:street'] ?? null,
        ]);

        if ($parts) {
            return implode(' ', $parts).', Pagsanjan, Laguna';
        }

        return ($barangay ? $barangay.', ' : '').'Pagsanjan, Laguna';
    }

    private function assignBarangay(float $lat, float $lon): ?string
    {
        foreach ($this->barangayFeatures() as $feature) {
            if ($this->pointInsideFeature([$lon, $lat], $feature)) {
                $p = $feature['properties'] ?? [];

                return (string) ($p['name'] ?? $p['NAME'] ?? $p['barangay'] ?? $p['BARANGAY']
                    ?? $p['brgy_name'] ?? $p['BRGY_NAME'] ?? $p['ADM4_EN'] ?? $p['adm4_en'] ?? '');
            }
        }

        return null;
    }

    private function supersedeApproximate(array $data): int
    {
        $candidates = Facility::where('source', 'sample_prototype_approximate')
            ->where('type', $data['type'])
            ->get();

        $count = 0;
        foreach ($candidates as $candidate) {
            $distance = $this->haversineDistance(
                $data['latitude'], $data['longitude'],
                (float) $candidate->latitude, (float) $candidate->longitude
            );

            if ($distance <= self::SUPERSEDE_RADIUS_METERS) {
                $candidate->update([
                    'is_active' => false,
                    'source'    => 'sample_prototype_approximate_superseded',
                ]);
                $count++;
            }
        }

        return $count;
    }

    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r    = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * asin(sqrt($a));
    }

    private function barangayFeatures(): array
    {
        if ($this->barangayFeatures !== null) {
            return $this->barangayFeatures;
        }

        $path = 'gis/boundaries/pagsanjan_barangays.geojson';
        if (! Storage::disk('local')->exists($path)) {
            return $this->barangayFeatures = [];
        }

        $decoded = json_decode(Storage::disk('local')->get($path), true);

        return $this->barangayFeatures = (is_array($decoded) && isset($decoded['features']))
            ? $decoded['features']
            : [];
    }

    private function pointInsideFeature(array $point, array $feature): bool
    {
        $rings = $this->polygonRings($feature);

        return $rings ? $this->pointInsideRings($point, $rings) : false;
    }

    private function polygonRings(array $feature): array
    {
        $geometry    = $feature['geometry'] ?? null;
        $type        = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;

        if ($type === 'Polygon' && is_array($coordinates)) {
            return $coordinates;
        }

        if ($type === 'MultiPolygon' && is_array($coordinates)) {
            $largestPolygon = [];
            $largestArea    = -1.0;

            foreach ($coordinates as $polygon) {
                if (! is_array($polygon) || ! isset($polygon[0])) {
                    continue;
                }

                $area = abs($this->ringSignedArea($polygon[0]));
                if ($area > $largestArea) {
                    $largestPolygon = $polygon;
                    $largestArea    = $area;
                }
            }

            return $largestPolygon;
        }

        return [];
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
        $x      = (float) $point[0];
        $y      = (float) $point[1];
        $inside = false;
        $count  = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            if (! is_array($ring[$i]) || ! is_array($ring[$j])
                || count($ring[$i]) < 2 || count($ring[$j]) < 2) {
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
        $area  = 0.0;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            if (! is_array($ring[$i]) || ! is_array($ring[$j])
                || count($ring[$i]) < 2 || count($ring[$j]) < 2) {
                continue;
            }

            $area += ((float) $ring[$j][0] * (float) $ring[$i][1])
                   - ((float) $ring[$i][0] * (float) $ring[$j][1]);
        }

        return $area / 2;
    }
}
