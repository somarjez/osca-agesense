<?php

namespace Database\Seeders;

use App\Services\PagsanjanFacilityDataset;
use Illuminate\Database\Seeder;

class PagsanjanFacilitySeeder extends Seeder
{
    public function run(): void
    {
        $stats = app(PagsanjanFacilityDataset::class)->sync();

        $this->command?->info(sprintf(
            'Pagsanjan facilities synchronized: %d dataset records, %d created, %d updated, %d unchanged, %d deactivated.',
            $stats['dataset'],
            $stats['created'],
            $stats['updated'],
            $stats['unchanged'],
            $stats['deactivated']
        ));
    }
}
