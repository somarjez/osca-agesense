<?php

namespace App\Console\Commands;

use App\Models\SeniorCitizen;
use Illuminate\Console\Command;

class PurgeExpiredRecords extends Command
{
    protected $signature = 'osca:purge-expired
                            {--years=5 : Retention period in years after soft-deletion}
                            {--execute : Actually purge; omit for a dry run}';

    protected $description = 'Permanently delete senior records soft-deleted longer than the retention period (default: 5 years). Runs as a dry run unless --execute is passed.';

    public function handle(): int
    {
        $years    = (int) $this->option('years');
        $execute  = $this->option('execute');
        $cutoff   = now()->subYears($years);

        $expired = SeniorCitizen::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->get();

        if ($expired->isEmpty()) {
            $this->info("No records found soft-deleted before {$cutoff->toDateString()}.");
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'OSCA ID', 'Name', 'Archived At'],
            $expired->map(fn($s) => [
                $s->id,
                $s->osca_id,
                $s->full_name,
                $s->deleted_at->toDateString(),
            ])
        );

        $this->newLine();
        $count = $expired->count();

        if (!$execute) {
            $this->warn("DRY RUN — {$count} record(s) would be permanently deleted. Pass --execute to apply.");
            return self::SUCCESS;
        }

        if (!$this->confirm("Permanently delete {$count} record(s)? This cannot be undone.")) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        foreach ($expired as $senior) {
            foreach ($senior->qolSurveys()->withTrashed()->get() as $survey) {
                $survey->mlResult?->recommendations()->forceDelete();
                $survey->mlResult?->forceDelete();
                $survey->forceDelete();
            }
            $senior->mlResults()->forceDelete();
            $senior->forceDelete();
        }

        $this->info("Permanently deleted {$count} record(s) archived before {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
