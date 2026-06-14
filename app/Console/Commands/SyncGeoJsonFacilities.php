<?php

namespace App\Console\Commands;

use App\Services\PagsanjanFacilityDataset;
use Illuminate\Console\Command;

class SyncGeoJsonFacilities extends Command
{
    protected $signature = 'facilities:sync-geojson
        {--dry-run : Validate and summarize the dataset without writing to the database}
        {--keep-existing : Do not deactivate stale managed facility records}';

    protected $description = 'Synchronize the committed Pagsanjan facility GeoJSON dataset into the facilities table.';

    public function handle(PagsanjanFacilityDataset $dataset): int
    {
        try {
            if ($this->option('dry-run')) {
                $records = $dataset->records();
                $this->info('Facility GeoJSON validation passed.');
                $this->line('Valid records: '.count($records));

                return self::SUCCESS;
            }

            $stats = $dataset->sync(! $this->option('keep-existing'));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Facility GeoJSON synchronization complete.');
        $this->table(
            ['Dataset', 'Created', 'Updated', 'Unchanged', 'Deactivated'],
            [[
                $stats['dataset'],
                $stats['created'],
                $stats['updated'],
                $stats['unchanged'],
                $stats['deactivated'],
            ]]
        );
        $this->line('Run php artisan gis:score-proximity to refresh stored accessibility metrics.');

        return self::SUCCESS;
    }
}
