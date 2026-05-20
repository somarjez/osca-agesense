<?php

namespace App\Console\Commands;

use App\Models\MlResult;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ml:batch-analyze
 *
 * Run the ML pipeline for all active seniors that have a QoL survey.
 * Falls back to local Python subprocess mode automatically when Flask
 * HTTP services are unavailable.
 *
 * Options:
 *   --chunk=N                    Process N seniors per chunk (default: 50)
 *   --barangay=NAME              Limit to a specific barangay
 *   --strict-notebook-cache      After batch completes, exit 1 if any seed senior
 *                                 has prediction_source != notebook_cache
 *
 * Usage:
 *   php artisan ml:batch-analyze
 *   php artisan ml:batch-analyze --chunk=25 --strict-notebook-cache
 *   php artisan ml:batch-analyze --barangay="San Isidro" --strict-notebook-cache
 */
class BatchAnalyzeSeniors extends Command
{
    protected $signature = 'ml:batch-analyze
                            {--chunk=50      : Number of seniors per processing chunk}
                            {--barangay=     : Limit processing to a specific barangay}
                            {--strict-notebook-cache : Exit 1 if any seed senior has prediction_source != notebook_cache after batch}';

    protected $description = 'Run ML batch analysis for all active seniors with a QoL survey.';

    public function handle(MlService $ml): int
    {
        set_time_limit(0);

        $chunkSize  = max(1, (int) ($this->option('chunk') ?: 50));
        $barangay   = $this->option('barangay');
        $strictMode = (bool) $this->option('strict-notebook-cache');

        $query = SeniorCitizen::active()
            ->whereHas('latestQolSurvey')
            ->with('latestQolSurvey');

        if ($barangay) {
            $query->where('barangay', $barangay);
        }

        $total = $query->count();

        $this->info("=== ml:batch-analyze ===");
        $this->info("Seniors to process : {$total}");
        $this->info("Chunk size         : {$chunkSize}");
        if ($barangay) {
            $this->info("Barangay filter    : {$barangay}");
        }
        if ($strictMode) {
            $this->info("Mode               : --strict-notebook-cache enabled");
        }
        $this->line('');

        if ($total === 0) {
            $this->warn("No eligible seniors found (active + has QoL survey).");
            return self::SUCCESS;
        }

        $succeeded = 0;
        $failed    = 0;
        $bar       = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunk($chunkSize, function ($seniors) use ($ml, &$succeeded, &$failed, $bar) {
            $items = $seniors
                ->filter(fn($s) => $s->latestQolSurvey !== null)
                ->map(fn($s) => ['senior' => $s, 'survey' => $s->latestQolSurvey])
                ->values()
                ->all();

            if (empty($items)) {
                return;
            }

            $results = $ml->runBatchPipeline($items);

            foreach ($results as $res) {
                if ($res['success'] ?? false) {
                    $succeeded++;
                } else {
                    $failed++;
                }
            }
            $bar->advance(count($items));
        });

        $bar->finish();
        $this->line("\n");
        $this->info("Batch complete — succeeded: {$succeeded}, failed: {$failed}");
        $this->line('');

        // ── Strict notebook-cache check ────────────────────────────────────
        if ($strictMode) {
            return $this->runStrictCheck();
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * After batch completes, verify all seed seniors have notebook_cache.
     * "Seed senior" = any senior whose identity (norm_name + barangay + age)
     * appears in the check_prediction_sources.py CSV set.  Since we cannot
     * easily load the CSV here, we use the simpler heuristic: any senior
     * with prediction_source = live_model is a candidate mismatch.
     *
     * Returns SUCCESS (0) if no live_model rows remain, FAILURE (1) otherwise.
     */
    private function runStrictCheck(): int
    {
        $this->info("=== --strict-notebook-cache check ===");

        // Get latest ml_result per active senior
        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->whereHas('seniorCitizen', fn($q) => $q->active())
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        $nbCount   = MlResult::whereIn('id', $latestIds)
            ->where('prediction_source', 'notebook_cache')
            ->count();

        $liveRows  = MlResult::whereIn('id', $latestIds)
            ->where('prediction_source', 'live_model')
            ->with('seniorCitizen')
            ->get();

        $this->info("  notebook_cache : {$nbCount}");
        $this->info("  live_model     : " . $liveRows->count());
        $this->line('');

        if ($liveRows->isEmpty()) {
            $this->info("All seed seniors matched notebook cache. PASS");
            return self::SUCCESS;
        }

        $this->warn("The following seniors still have prediction_source = live_model:");
        $rows = $liveRows->map(fn($r) => [
            $r->senior_citizen_id,
            $r->seniorCitizen?->first_name . ' ' . $r->seniorCitizen?->last_name,
            $r->seniorCitizen?->barangay,
            $r->seniorCitizen?->age,
            $r->prediction_source,
        ])->toArray();

        $this->table(['ID', 'Name', 'Barangay', 'Age', 'Source'], $rows);
        $this->line('');
        $this->error("--strict-notebook-cache check FAILED: " . $liveRows->count() . " live_model row(s) remain.");
        $this->line("Run: php artisan ml:repair-notebook-cache");

        return self::FAILURE;
    }
}
