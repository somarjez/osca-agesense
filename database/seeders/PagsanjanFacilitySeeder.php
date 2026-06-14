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
                'latitude' => 14.2717000,
                'longitude' => 121.4554000,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Pagsanjan Rural Health Unit',
                'type' => 'Health Center',
                'barangay' => 'Barangay II (Poblacion)',
                'address' => 'Approximate RHU area, Pagsanjan, Laguna',
                'latitude' => 14.2758570,
                'longitude' => 121.4493940,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Pagsanjan Community Hospital',
                'type' => 'Hospital',
                'barangay' => 'Barangay II (Poblacion)',
                'address' => 'Approximate hospital area, Pagsanjan, Laguna',
                'latitude' => 14.2758570,
                'longitude' => 121.4501940,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Pagsanjan Senior Citizens Center',
                'type' => 'Senior Center',
                'barangay' => 'Barangay I (Poblacion)',
                'address' => 'Approximate senior center area, Pagsanjan, Laguna',
                'latitude' => 14.2708000,
                'longitude' => 121.4549000,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Pagsanjan Public Market',
                'type' => 'Public Market',
                'barangay' => 'Barangay I (Poblacion)',
                'address' => 'Approximate public market area, Pagsanjan, Laguna',
                'latitude' => 14.2698000,
                'longitude' => 121.4547000,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Pagsanjan Pharmacy Access Point',
                'type' => 'Pharmacy',
                'barangay' => 'Barangay II (Poblacion)',
                'address' => 'Approximate pharmacy area, Pagsanjan, Laguna',
                'latitude' => 14.2758570,
                'longitude' => 121.4509940,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Pagsanjan Parish Church',
                'type' => 'Church',
                'barangay' => 'Barangay I (Poblacion)',
                'address' => 'Approximate parish church area, Pagsanjan, Laguna',
                'latitude' => 14.2713000,
                'longitude' => 121.4558000,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Sabang Community Church',
                'type' => 'Church',
                'barangay' => 'Sabang',
                'address' => 'Approximate church area, Sabang, Pagsanjan',
                'latitude' => 14.2747000,
                'longitude' => 121.4523000,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Pagsanjan Transport Terminal',
                'type' => 'Transport Hub',
                'barangay' => 'Barangay I (Poblacion)',
                'address' => 'Approximate transport hub area, Pagsanjan, Laguna',
                'latitude' => 14.2766570,
                'longitude' => 121.4493940,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Barangay Hall - Sabang',
                'type' => 'Barangay Hall',
                'barangay' => 'Sabang',
                'address' => 'Approximate barangay hall area, Sabang, Pagsanjan',
                'latitude' => 14.2750000,
                'longitude' => 121.4527000,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Barangay Hall - Pinagsanjan',
                'type' => 'Barangay Hall',
                'barangay' => 'Pinagsanjan',
                'address' => 'Approximate barangay hall area, Pinagsanjan, Pagsanjan',
                'latitude' => 14.2641380,
                'longitude' => 121.4582860,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Barangay Hall - Maulawin',
                'type' => 'Barangay Hall',
                'barangay' => 'Maulawin',
                'address' => 'Approximate barangay hall area, Maulawin, Pagsanjan',
                'latitude' => 14.2737000,
                'longitude' => 121.4625000,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],
            [
                'name' => 'Barangay Hall - Lambac',
                'type' => 'Barangay Hall',
                'barangay' => 'Lambac',
                'address' => 'Approximate barangay hall area, Lambac, Pagsanjan',
                'latitude' => 14.2501550,
                'longitude' => 121.4595600,
                'source' => 'sample_prototype_approximate',
                'is_active' => true,
            ],

            // --- Anibong ---
            ['name' => 'Barangay Hall - Anibong',       'type' => 'Barangay Hall', 'barangay' => 'Anibong',           'address' => 'Approximate barangay hall, Anibong, Pagsanjan',      'latitude' => 14.2356110, 'longitude' => 121.4635070, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Anibong Health Post',            'type' => 'Health Center', 'barangay' => 'Anibong',           'address' => 'Approximate health post, Anibong, Pagsanjan',        'latitude' => 14.2356110, 'longitude' => 121.4643070, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Anibong Community Chapel',       'type' => 'Church',        'barangay' => 'Anibong',           'address' => 'Approximate chapel, Anibong, Pagsanjan',             'latitude' => 14.2782000, 'longitude' => 121.4591000, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Biñan ---
            ['name' => 'Barangay Hall - Biñan',          'type' => 'Barangay Hall', 'barangay' => 'Biñan',             'address' => 'Approximate barangay hall, Biñan, Pagsanjan',        'latitude' => 14.2728000, 'longitude' => 121.4468000, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Biñan Health Post',               'type' => 'Health Center', 'barangay' => 'Biñan',             'address' => 'Approximate health post, Biñan, Pagsanjan',          'latitude' => 14.2731000, 'longitude' => 121.4468000, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Biñan Community Chapel',          'type' => 'Church',        'barangay' => 'Biñan',             'address' => 'Approximate chapel, Biñan, Pagsanjan',               'latitude' => 14.2728000, 'longitude' => 121.4471000, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Buboy ---
            ['name' => 'Barangay Hall - Buboy',          'type' => 'Barangay Hall', 'barangay' => 'Buboy',             'address' => 'Approximate barangay hall, Buboy, Pagsanjan',        'latitude' => 14.2742000, 'longitude' => 121.4618000, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Buboy Health Post',               'type' => 'Health Center', 'barangay' => 'Buboy',             'address' => 'Approximate health post, Buboy, Pagsanjan',          'latitude' => 14.2333510, 'longitude' => 121.4227800, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Buboy Community Chapel',          'type' => 'Church',        'barangay' => 'Buboy',             'address' => 'Approximate chapel, Buboy, Pagsanjan',               'latitude' => 14.2333510, 'longitude' => 121.4235800, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Cabanbanan ---
            ['name' => 'Barangay Hall - Cabanbanan',     'type' => 'Barangay Hall', 'barangay' => 'Cabanbanan',        'address' => 'Approximate barangay hall, Cabanbanan, Pagsanjan',   'latitude' => 14.2648000, 'longitude' => 121.4528000, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Cabanbanan Health Post',          'type' => 'Health Center', 'barangay' => 'Cabanbanan',        'address' => 'Approximate health post, Cabanbanan, Pagsanjan',     'latitude' => 14.2651000, 'longitude' => 121.4528000, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Cabanbanan Community Chapel',     'type' => 'Church',        'barangay' => 'Cabanbanan',        'address' => 'Approximate chapel, Cabanbanan, Pagsanjan',          'latitude' => 14.2648000, 'longitude' => 121.4531000, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Calusiche ---
            ['name' => 'Barangay Hall - Calusiche',      'type' => 'Barangay Hall', 'barangay' => 'Calusiche',         'address' => 'Approximate barangay hall, Calusiche, Pagsanjan',    'latitude' => 14.2550160, 'longitude' => 121.4442660, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Calusiche Health Post',           'type' => 'Health Center', 'barangay' => 'Calusiche',         'address' => 'Approximate health post, Calusiche, Pagsanjan',      'latitude' => 14.2550160, 'longitude' => 121.4450660, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Calusiche Community Chapel',      'type' => 'Church',        'barangay' => 'Calusiche',         'address' => 'Approximate chapel, Calusiche, Pagsanjan',           'latitude' => 14.2550160, 'longitude' => 121.4458660, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Dingin ---
            ['name' => 'Barangay Hall - Dingin',         'type' => 'Barangay Hall', 'barangay' => 'Dingin',            'address' => 'Approximate barangay hall, Dingin, Pagsanjan',       'latitude' => 14.2469250, 'longitude' => 121.4493650, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Dingin Health Post',              'type' => 'Health Center', 'barangay' => 'Dingin',            'address' => 'Approximate health post, Dingin, Pagsanjan',         'latitude' => 14.2469250, 'longitude' => 121.4501650, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Dingin Community Chapel',         'type' => 'Church',        'barangay' => 'Dingin',            'address' => 'Approximate chapel, Dingin, Pagsanjan',              'latitude' => 14.2758000, 'longitude' => 121.4547000, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Layugan ---
            ['name' => 'Barangay Hall - Layugan',        'type' => 'Barangay Hall', 'barangay' => 'Layugan',           'address' => 'Approximate barangay hall, Layugan, Pagsanjan',      'latitude' => 14.2638000, 'longitude' => 121.4572000, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Layugan Health Post',             'type' => 'Health Center', 'barangay' => 'Layugan',           'address' => 'Approximate health post, Layugan, Pagsanjan',        'latitude' => 14.2419680, 'longitude' => 121.4394980, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Layugan Community Chapel',        'type' => 'Church',        'barangay' => 'Layugan',           'address' => 'Approximate chapel, Layugan, Pagsanjan',             'latitude' => 14.2638000, 'longitude' => 121.4575000, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Magdapio ---
            ['name' => 'Barangay Hall - Magdapio',       'type' => 'Barangay Hall', 'barangay' => 'Magdapio',          'address' => 'Approximate barangay hall, Magdapio, Pagsanjan',     'latitude' => 14.2716670, 'longitude' => 121.4624710, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Magdapio Health Post',            'type' => 'Health Center', 'barangay' => 'Magdapio',          'address' => 'Approximate health post, Magdapio, Pagsanjan',       'latitude' => 14.2716670, 'longitude' => 121.4632710, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Magdapio Community Chapel',       'type' => 'Church',        'barangay' => 'Magdapio',          'address' => 'Approximate chapel, Magdapio, Pagsanjan',            'latitude' => 14.2802000, 'longitude' => 121.4559000, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Sampaloc ---
            ['name' => 'Barangay Hall - Sampaloc',       'type' => 'Barangay Hall', 'barangay' => 'Sampaloc',          'address' => 'Approximate barangay hall, Sampaloc, Pagsanjan',     'latitude' => 14.2764000, 'longitude' => 121.4558000, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Sampaloc Health Post',            'type' => 'Health Center', 'barangay' => 'Sampaloc',          'address' => 'Approximate health post, Sampaloc, Pagsanjan',       'latitude' => 14.2767000, 'longitude' => 121.4558000, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Sampaloc Community Chapel',       'type' => 'Church',        'barangay' => 'Sampaloc',          'address' => 'Approximate chapel, Sampaloc, Pagsanjan',            'latitude' => 14.2764000, 'longitude' => 121.4561000, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- San Isidro ---
            ['name' => 'Barangay Hall - San Isidro',     'type' => 'Barangay Hall', 'barangay' => 'San Isidro',        'address' => 'Approximate barangay hall, San Isidro, Pagsanjan',   'latitude' => 14.2668000, 'longitude' => 121.4612000, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'San Isidro Health Post',          'type' => 'Health Center', 'barangay' => 'San Isidro',        'address' => 'Approximate health post, San Isidro, Pagsanjan',     'latitude' => 14.2785380, 'longitude' => 121.4549490, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'San Isidro Community Chapel',     'type' => 'Church',        'barangay' => 'San Isidro',        'address' => 'Approximate chapel, San Isidro, Pagsanjan',          'latitude' => 14.2668000, 'longitude' => 121.4615000, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // --- Fill gaps in existing barangays ---

            // Lambac (had Hall only)
            ['name' => 'Lambac Health Post',             'type' => 'Health Center', 'barangay' => 'Lambac',            'address' => 'Approximate health post, Lambac, Pagsanjan',         'latitude' => 14.2501550, 'longitude' => 121.4603600, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Lambac Community Church',         'type' => 'Church',        'barangay' => 'Lambac',            'address' => 'Approximate church, Lambac, Pagsanjan',              'latitude' => 14.2501550, 'longitude' => 121.4611600, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Lambac Community Store',          'type' => 'Community Store', 'barangay' => 'Lambac',           'address' => 'Approximate community store, Lambac, Pagsanjan',     'latitude' => 14.2686000, 'longitude' => 121.4592000, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // Maulawin (had Hall only)
            ['name' => 'Maulawin Health Post',           'type' => 'Health Center', 'barangay' => 'Maulawin',          'address' => 'Approximate health post, Maulawin, Pagsanjan',       'latitude' => 14.2653210, 'longitude' => 121.4520600, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Maulawin Community Church',       'type' => 'Church',        'barangay' => 'Maulawin',          'address' => 'Approximate church, Maulawin, Pagsanjan',            'latitude' => 14.2653210, 'longitude' => 121.4528600, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Maulawin Community Store',        'type' => 'Community Store', 'barangay' => 'Maulawin',         'address' => 'Approximate community store, Maulawin, Pagsanjan',   'latitude' => 14.2653210, 'longitude' => 121.4536600, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // Pinagsanjan (had Hall only)
            ['name' => 'Pinagsanjan Health Post',        'type' => 'Health Center', 'barangay' => 'Pinagsanjan',       'address' => 'Approximate health post, Pinagsanjan, Pagsanjan',    'latitude' => 14.2641380, 'longitude' => 121.4590860, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Pinagsanjan Community Church',    'type' => 'Church',        'barangay' => 'Pinagsanjan',       'address' => 'Approximate church, Pinagsanjan, Pagsanjan',         'latitude' => 14.2641380, 'longitude' => 121.4598860, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Pinagsanjan Community Store',     'type' => 'Community Store', 'barangay' => 'Pinagsanjan',      'address' => 'Approximate community store, Pinagsanjan, Pagsanjan', 'latitude' => 14.2649380, 'longitude' => 121.4582860, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // Sabang (had Hall + Church, needs Health Center + Store)
            ['name' => 'Sabang Health Post',             'type' => 'Health Center', 'barangay' => 'Sabang',            'address' => 'Approximate health post, Sabang, Pagsanjan',         'latitude' => 14.2753000, 'longitude' => 121.4527000, 'source' => 'sample_prototype_approximate', 'is_active' => true],
            ['name' => 'Sabang Community Store',          'type' => 'Community Store', 'barangay' => 'Sabang',           'address' => 'Approximate community store, Sabang, Pagsanjan',     'latitude' => 14.2534580, 'longitude' => 121.4367040, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // Barangay I (Poblacion) — has many, add distinct Health Center
            ['name' => 'Barangay I Health Center',       'type' => 'Health Center', 'barangay' => 'Barangay I (Poblacion)', 'address' => 'Approximate barangay health center, Barangay I, Pagsanjan', 'latitude' => 14.2766570, 'longitude' => 121.4501940, 'source' => 'sample_prototype_approximate', 'is_active' => true],

            // Barangay II (Poblacion) — has RHU/Hospital/Pharmacy, add Church
            ['name' => 'Barangay II Community Church',   'type' => 'Church',        'barangay' => 'Barangay II (Poblacion)', 'address' => 'Approximate church, Barangay II, Pagsanjan',   'latitude' => 14.2766570, 'longitude' => 121.4509940, 'source' => 'sample_prototype_approximate', 'is_active' => true],
        ];

        foreach ($facilities as $facility) {
            Facility::updateOrCreate(
                ['name' => $facility['name']],
                $facility
            );
        }
    }
}
