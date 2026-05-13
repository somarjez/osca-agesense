<?php

namespace Database\Seeders;

use App\Models\Facility;
use Illuminate\Database\Seeder;

class PagsanjanFacilitySeeder extends Seeder
{
    public function run(): void
    {
        $facilities = [
            [
                'name' => 'Pagsanjan Municipal Hall',
                'type' => 'Municipal Hall',
                'barangay' => 'Barangay I (Poblacion)',
                'address' => 'Approximate municipal center, Pagsanjan, Laguna',
                'latitude' => 14.2717,
                'longitude' => 121.4554,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Pagsanjan Rural Health Unit',
                'type' => 'Health Center',
                'barangay' => 'Barangay II (Poblacion)',
                'address' => 'Approximate RHU area, Pagsanjan, Laguna',
                'latitude' => 14.2709,
                'longitude' => 121.4568,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Pagsanjan Community Hospital',
                'type' => 'Hospital',
                'barangay' => 'Barangay II (Poblacion)',
                'address' => 'Approximate hospital area, Pagsanjan, Laguna',
                'latitude' => 14.2724,
                'longitude' => 121.4579,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Pagsanjan Senior Citizens Center',
                'type' => 'Senior Center',
                'barangay' => 'Barangay I (Poblacion)',
                'address' => 'Approximate senior center area, Pagsanjan, Laguna',
                'latitude' => 14.2708,
                'longitude' => 121.4549,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Pagsanjan Public Market',
                'type' => 'Public Market',
                'barangay' => 'Barangay I (Poblacion)',
                'address' => 'Approximate public market area, Pagsanjan, Laguna',
                'latitude' => 14.2698,
                'longitude' => 121.4547,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Pagsanjan Pharmacy Access Point',
                'type' => 'Pharmacy',
                'barangay' => 'Barangay II (Poblacion)',
                'address' => 'Approximate pharmacy area, Pagsanjan, Laguna',
                'latitude' => 14.2699,
                'longitude' => 121.4562,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Pagsanjan Parish Church',
                'type' => 'Church',
                'barangay' => 'Barangay I (Poblacion)',
                'address' => 'Approximate parish church area, Pagsanjan, Laguna',
                'latitude' => 14.2713,
                'longitude' => 121.4558,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Sabang Community Church',
                'type' => 'Church',
                'barangay' => 'Sabang',
                'address' => 'Approximate church area, Sabang, Pagsanjan',
                'latitude' => 14.2747,
                'longitude' => 121.4523,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Pagsanjan Transport Terminal',
                'type' => 'Transport Hub',
                'barangay' => 'Barangay I (Poblacion)',
                'address' => 'Approximate transport hub area, Pagsanjan, Laguna',
                'latitude' => 14.2689,
                'longitude' => 121.4558,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Barangay Hall - Sabang',
                'type' => 'Barangay Hall',
                'barangay' => 'Sabang',
                'address' => 'Approximate barangay hall area, Sabang, Pagsanjan',
                'latitude' => 14.2750,
                'longitude' => 121.4527,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Barangay Hall - Pinagsanjan',
                'type' => 'Barangay Hall',
                'barangay' => 'Pinagsanjan',
                'address' => 'Approximate barangay hall area, Pinagsanjan, Pagsanjan',
                'latitude' => 14.2659,
                'longitude' => 121.4513,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Barangay Hall - Maulawin',
                'type' => 'Barangay Hall',
                'barangay' => 'Maulawin',
                'address' => 'Approximate barangay hall area, Maulawin, Pagsanjan',
                'latitude' => 14.2740,
                'longitude' => 121.4626,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Barangay Hall - Lambac',
                'type' => 'Barangay Hall',
                'barangay' => 'Lambac',
                'address' => 'Approximate barangay hall area, Lambac, Pagsanjan',
                'latitude' => 14.2689,
                'longitude' => 121.4592,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
        ];

        foreach ($facilities as $facility) {
            Facility::updateOrCreate(
                ['name' => $facility['name']],
                $facility
            );
        }
    }
}
