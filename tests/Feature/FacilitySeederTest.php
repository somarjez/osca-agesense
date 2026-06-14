<?php

namespace Tests\Feature;

use App\Http\Controllers\GisApiController;
use App\Models\Facility;
use App\Services\PagsanjanFacilityDataset;
use Database\Seeders\PagsanjanFacilitySeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FacilitySeederTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function seeder_imports_all_geojson_facilities_with_barangays(): void
    {
        $records = app(PagsanjanFacilityDataset::class)->records();
        $osmIds = array_column($records, 'osm_id');

        $this->seed(PagsanjanFacilitySeeder::class);

        $imported = Facility::query()->whereIn('osm_id', $osmIds);

        $this->assertCount(155, $records);
        $this->assertSame(155, (clone $imported)->where('is_active', true)->count());
        $this->assertSame(0, (clone $imported)->whereNull('barangay')->count());
        $this->assertSame(0, (clone $imported)->whereNull('osm_id')->count());
        $this->assertSame(16, (clone $imported)->distinct()->count('barangay'));
        $this->assertSame(0, (clone $imported)->whereIn('barangay', [
            'Barangay I (Pob.)',
            'Barangay II (Pob.)',
            'BiÃ±an',
            'BiÃƒÂ±an',
        ])->count());
    }

    #[Test]
    public function repeated_seeding_is_idempotent_and_preserves_facility_ids(): void
    {
        $dataset = app(PagsanjanFacilityDataset::class);
        $osmIds = array_column($dataset->records(), 'osm_id');

        $this->seed(PagsanjanFacilitySeeder::class);
        $firstIds = Facility::query()
            ->whereIn('osm_id', $osmIds)
            ->pluck('id', 'osm_id')
            ->all();

        $this->seed(PagsanjanFacilitySeeder::class);
        $secondIds = Facility::query()
            ->whereIn('osm_id', $osmIds)
            ->pluck('id', 'osm_id')
            ->all();

        $this->assertCount(155, $firstIds);
        $this->assertSame($firstIds, $secondIds);
    }

    #[Test]
    public function seeder_deactivates_obsolete_approximate_facilities_without_deleting_them(): void
    {
        $obsolete = Facility::create([
            'name' => 'Obsolete Prototype Facility',
            'type' => 'Health Center',
            'barangay' => 'Sabang',
            'address' => 'Approximate location',
            'latitude' => 14.25,
            'longitude' => 121.44,
            'source' => 'sample_prototype_approximate',
            'is_active' => true,
        ]);

        $this->seed(PagsanjanFacilitySeeder::class);

        $obsolete->refresh();
        $this->assertFalse((bool) $obsolete->is_active);
        $this->assertDatabaseHas('facilities', ['id' => $obsolete->id]);
    }

    #[Test]
    public function facility_api_reads_database_records_with_cacheable_facility_ids(): void
    {
        $this->seed(PagsanjanFacilitySeeder::class);

        $payload = app(GisApiController::class)->facilities()->getData(true);
        $features = $payload['features'] ?? [];

        $this->assertSame('database', $payload['source'] ?? null);
        $this->assertSame(155, $payload['total'] ?? null);
        $this->assertCount(155, $features);
        $this->assertNotContains(null, array_column(array_column($features, 'properties'), 'facility_id'));
        $this->assertNotContains(null, array_column(array_column($features, 'properties'), 'barangay'));
    }

    #[Test]
    public function verified_barangay_hall_coordinates_are_seeded(): void
    {
        $this->seed(PagsanjanFacilitySeeder::class);

        $expected = [
            'Barangay Hall - Anibong' => [14.2279, 121.4632],
            'Barangay Hall - Calusiche' => [14.258398, 121.4476226],
            'Barangay Hall - Lambac' => [14.2542908, 121.4537821],
            'Barangay Hall - Magdapio' => [14.2721328, 121.4599537],
            'Barangay Hall - Barangay I (Poblacion)' => [14.27502, 121.4556649],
            'Barangay Hall - Barangay II (Poblacion)' => [14.2757099, 121.4529784],
        ];

        foreach ($expected as $name => [$latitude, $longitude]) {
            $facility = Facility::query()->where('name', $name)->where('is_active', true)->first();

            $this->assertNotNull($facility, $name.' should be active.');
            $this->assertEqualsWithDelta($latitude, (float) $facility->latitude, 0.0000001);
            $this->assertEqualsWithDelta($longitude, (float) $facility->longitude, 0.0000001);
        }
    }
}
