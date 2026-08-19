<?php

namespace App\Services;

use App\Models\Facility;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PagsanjanFacilityDataset
{
    public const DATASET_PATH = 'database/gis/facilities/pagsanjan_facilities_thesis_final_cleaned.geojson';

    private const MANAGED_SOURCES = [
        'Bing',
        'google_maps_public_listing',
        'openstreetmap',
        'osm_admin_relation_center_supplement',
        'sample_prototype_approximate',
        'sample_prototype_approximate_superseded',
        'thesis_final_cleaned_geojson',
        'user_provided_barangay_vicinity_coordinate',
    ];

    private ?array $barangayFeatures = null;

    public function records(): array
    {
        $path = base_path(self::DATASET_PATH);
        if (! is_file($path)) {
            throw new RuntimeException('Facility GeoJSON dataset was not found: '.$path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $features = is_array($decoded) ? ($decoded['features'] ?? null) : null;

        if (! is_array($features)) {
            throw new RuntimeException('Facility GeoJSON dataset contains no valid features array.');
        }

        $records = [];

        foreach ($features as $feature) {
            $record = $this->recordFromFeature(is_array($feature) ? $feature : []);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        if (count($records) !== count($features)) {
            throw new RuntimeException('Every facility GeoJSON feature must have valid coordinates and an OSM identifier.');
        }

        $uniqueIds = array_unique(array_column($records, 'osm_id'));
        if (count($uniqueIds) !== count($records)) {
            throw new RuntimeException('Facility GeoJSON dataset contains duplicate OSM identifiers.');
        }

        return $records;
    }

    public function sync(bool $deactivateStale = true): array
    {
        $records = $this->records();
        $stats = [
            'dataset' => count($records),
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'deactivated' => 0,
        ];

        DB::transaction(function () use ($records, $deactivateStale, &$stats) {
            $datasetIds = array_column($records, 'osm_id');

            foreach ($records as $record) {
                $facility = Facility::query()->where('osm_id', $record['osm_id'])->first();

                if (! $facility) {
                    Facility::create($record);
                    $stats['created']++;

                    continue;
                }

                $facility->fill($record);
                if ($facility->isDirty()) {
                    $facility->save();
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }
            }

            if ($deactivateStale) {
                $stats['deactivated'] = Facility::query()
                    ->where('is_active', DB::raw('true'))
                    ->where(function ($query) use ($datasetIds) {
                        $query->whereIn('source', self::MANAGED_SOURCES)
                            ->orWhere(function ($query) use ($datasetIds) {
                                $query->whereNotNull('osm_id')
                                    ->whereNotIn('osm_id', $datasetIds);
                            });
                    })
                    ->where(function ($query) use ($datasetIds) {
                        $query->whereNull('osm_id')
                            ->orWhereNotIn('osm_id', $datasetIds);
                    })
                    ->update(['is_active' => false]);
            }
        });

        // Mirrors ImportOsmFacilities' invalidation: the profile facility
        // panel and the GIS map both read Facility::cachedActiveWithCoordinates()
        // (a 24h cache), which this seeder path previously never invalidated —
        // a reseed could sit behind a stale/empty cache for up to a day.
        Cache::forget('facilities.active_with_coordinates');

        return $stats;
    }

    private function recordFromFeature(array $feature): ?array
    {
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $coordinates = $feature['geometry']['coordinates'] ?? null;
        $latitude = $properties['lat'] ?? ($coordinates[1] ?? null);
        $longitude = $properties['lon'] ?? ($coordinates[0] ?? null);
        $osmType = trim((string) ($properties['osm_type'] ?? ''));
        $osmId = trim((string) ($properties['osm_id'] ?? ''));

        if (! is_numeric($latitude) || ! is_numeric($longitude) || $osmType === '' || $osmId === '') {
            return null;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        $barangay = trim((string) ($properties['addr_barangay'] ?? ''));

        if ($barangay === '') {
            $barangay = $this->barangayAtCoordinates($longitude, $latitude) ?? '';
        }
        $barangay = $this->canonicalBarangayName($barangay);

        $rawTags = is_array($properties['raw_tags'] ?? null) ? $properties['raw_tags'] : [];

        return [
            'osm_id' => $osmType.':'.$osmId,
            'name' => trim((string) ($properties['name'] ?? '')) ?: 'Unnamed Facility',
            'type' => $this->facilityType($properties),
            'barangay' => $barangay !== '' ? $barangay : null,
            'address' => trim((string) ($properties['addr_full'] ?? '')) ?: null,
            'latitude' => round($latitude, 7),
            'longitude' => round($longitude, 7),
            'source' => trim((string) ($rawTags['source'] ?? '')) ?: 'thesis_final_cleaned_geojson',
            'is_active' => true,
        ];
    }

    private function facilityType(array $properties): string
    {
        $category = strtolower((string) ($properties['category'] ?? ''));
        $amenity = strtolower((string) ($properties['amenity'] ?? ''));
        $healthcare = strtolower((string) ($properties['healthcare'] ?? ''));
        $shop = strtolower((string) ($properties['shop'] ?? ''));
        $name = strtolower((string) ($properties['name'] ?? ''));

        if ($category === 'pharmacy' || $amenity === 'pharmacy' || $healthcare === 'pharmacy'
            || str_contains($name, 'botika') || str_contains($name, 'drug')) {
            return 'Pharmacy';
        }

        if (str_contains($name, 'hospital') || $amenity === 'hospital' || $healthcare === 'hospital') {
            return 'Hospital';
        }

        if ($category === 'healthcare'
            || in_array($amenity, ['clinic', 'dentist', 'doctors', 'health_post'], true)
            || $healthcare !== '') {
            return 'Health Center';
        }

        if ($category === 'barangay_hall' || str_contains($name, 'barangay hall') || str_contains($name, 'pambarangay')) {
            return 'Barangay Hall';
        }

        if ($category === 'community_social' || str_contains($name, 'senior citizens') || str_contains($name, 'osca')) {
            return 'Senior Center';
        }

        if ($category === 'government_office') {
            return str_contains($name, 'municipal') ? 'Municipal Hall' : 'Government Office';
        }

        if ($category === 'emergency_service') {
            return str_contains($name, 'fire')
                ? 'Fire Station'
                : (str_contains($name, 'police') ? 'Police Station' : 'Emergency Service');
        }

        if ($category === 'worship' || $amenity === 'place_of_worship') {
            return 'Church';
        }

        if ($category === 'market_food') {
            return in_array($shop, ['supermarket', 'mall'], true)
                ? 'Supermarket'
                : (str_contains($name, 'market') ? 'Public Market' : 'Community Store');
        }

        if ($category === 'food') {
            return 'Food Service';
        }

        return 'Community Facility';
    }

    private function barangayAtCoordinates(float $longitude, float $latitude): ?string
    {
        foreach ($this->barangayFeatures() as $feature) {
            if (! $this->pointInsideFeature([$longitude, $latitude], $feature)) {
                continue;
            }

            $properties = $feature['properties'] ?? [];
            $name = $properties['name']
                ?? $properties['NAME']
                ?? $properties['barangay']
                ?? $properties['BARANGAY']
                ?? $properties['brgy_name']
                ?? $properties['BRGY_NAME']
                ?? $properties['ADM4_EN']
                ?? $properties['adm4_en']
                ?? null;

            return $name !== null && trim((string) $name) !== ''
                ? $this->canonicalBarangayName((string) $name)
                : null;
        }

        return null;
    }

    private function canonicalBarangayName(string $name): string
    {
        $name = trim($name);

        return match ($name) {
            'Barangay I (Pob.)' => 'Barangay I (Poblacion)',
            'Barangay II (Pob.)' => 'Barangay II (Poblacion)',
            'BiÃ±an', 'BiÃƒÂ±an' => 'Biñan',
            default => $name,
        };
    }

    private function barangayFeatures(): array
    {
        if ($this->barangayFeatures !== null) {
            return $this->barangayFeatures;
        }

        $path = 'gis/boundaries/pagsanjan_barangays.geojson';
        if (! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('Pagsanjan barangay boundary dataset was not found: storage/app/'.$path);
        }

        $decoded = json_decode(Storage::disk('local')->get($path), true);
        $features = is_array($decoded) ? ($decoded['features'] ?? null) : null;

        if (! is_array($features)) {
            throw new RuntimeException('Pagsanjan barangay boundary dataset contains no valid features array.');
        }

        return $this->barangayFeatures = $features;
    }

    private function pointInsideFeature(array $point, array $feature): bool
    {
        $geometry = $feature['geometry'] ?? [];
        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;
        $polygons = $type === 'Polygon'
            ? [$coordinates]
            : ($type === 'MultiPolygon' ? $coordinates : []);

        if (! is_array($polygons)) {
            return false;
        }

        foreach ($polygons as $rings) {
            if (is_array($rings) && $this->pointInsideRings($point, $rings)) {
                return true;
            }
        }

        return false;
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
}
