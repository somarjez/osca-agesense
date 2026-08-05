<?php

namespace App\Console\Commands;

use App\Models\MlResult;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use App\Support\SeniorDataVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ml:batch-analyze
 *
 * Run the ML pipeline for all active seniors that have a QoL survey.
 * Falls back to local Python subprocess mode automatically when Flask
 * HTTP services are unavailable.
 *
 * By default, valid non-stale results with a matching model_version are reused
 * and not recomputed. Use --force to recompute every row regardless.
 *
 * Options:
 *   --chunk=N                    Process N seniors per chunk (default: 50)
 *   --barangay=NAME              Limit to a specific barangay
 *   --force                      Recompute all rows, ignoring existing cached results
 *   --stale-only                 Recompute only stale or version-mismatched rows
 *   --strict-notebook-cache      After batch completes, exit 1 if any seed senior
 *                                 has prediction_source != notebook_cache
 */
class BatchAnalyzeSeniors extends Command
{
    protected $signature = 'ml:batch-analyze
                            {--chunk=50           : Number of seniors per processing chunk}
                            {--barangay=          : Limit processing to a specific barangay}
                            {--force              : Recompute all rows, ignoring existing cached results}
                            {--stale-only         : Only recompute stale or version-mismatched rows}
                            {--strict-notebook-cache : Exit 1 if any seed senior has prediction_source != notebook_cache after batch}';

    protected $description = 'Run ML batch analysis for all active seniors with a QoL survey.';

    public function handle(MlService $ml): int
    {
        set_time_limit(0);

        $chunkSize = max(1, (int) ($this->option('chunk') ?: 50));
        $barangay = $this->option('barangay');
        $force = (bool) $this->option('force');
        $staleOnly = (bool) $this->option('stale-only');
        $strictMode = (bool) $this->option('strict-notebook-cache');

        $query = SeniorCitizen::active()
            ->whereHas('latestQolSurvey')
            ->with('latestQolSurvey');

        if ($barangay) {
            $query->where('barangay', $barangay);
        }

        $total = $query->count();

        $this->info('=== ml:batch-analyze ===');
        $this->info("Seniors to process : {$total}");
        $this->info("Chunk size         : {$chunkSize}");
        if ($barangay) {
            $this->info("Barangay filter    : {$barangay}");
        }
        if ($force) {
            $this->info('Mode               : --force (recompute all)');
        }
        if ($staleOnly) {
            $this->info('Mode               : --stale-only (skip valid rows)');
        }
        if ($strictMode) {
            $this->info('Post-check         : --strict-notebook-cache enabled');
        }
        $this->line('');

        if ($total === 0) {
            $this->warn('No eligible seniors found (active + has QoL survey).');

            return self::SUCCESS;
        }

        // Counters
        $reused = 0;
        $staleRecomputed = 0;
        $versionMismatchRecomputed = 0;
        $missingComputed = 0;
        $fallbackUpgraded = 0;
        $failed = 0;
        $notebookCacheCount = 0;
        $liveModelCount = 0;
        $fallbackCount = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunk($chunkSize, function ($seniors) use (
            $ml, $force, $staleOnly,
            &$reused, &$staleRecomputed, &$versionMismatchRecomputed,
            &$missingComputed, &$fallbackUpgraded, &$failed,
            &$notebookCacheCount, &$liveModelCount, &$fallbackCount,
            $bar
        ) {
            foreach ($seniors as $senior) {
                $survey = $senior->latestQolSurvey;
                if (! $survey) {
                    $bar->advance();

                    continue;
                }

                // Determine what to do with this senior's existing result
                $existing = MlResult::where('senior_citizen_id', $senior->id)
                    ->where('qol_survey_id', $survey->id)
                    ->orderByDesc('id')
                    ->first();

                $needsRecompute = $this->needsRecompute($existing, $ml, $force, $staleOnly);

                if (! $needsRecompute) {
                    // Reuse valid result — track source counts
                    $reused++;
                    match ($existing->prediction_source) {
                        'notebook_cache' => $notebookCacheCount++,
                        'live_model' => $liveModelCount++,
                        default => $fallbackCount++,
                    };
                    $bar->advance();

                    continue;
                }

                // Determine recompute reason for counter tracking
                $recomputeReason = $this->recomputeReason($existing, $ml, $force);

                try {
                    $result = $ml->runPipeline($senior, $survey, force: true);

                    match ($recomputeReason) {
                        'stale' => $staleRecomputed++,
                        'version_mismatch' => $versionMismatchRecomputed++,
                        'missing' => $missingComputed++,
                        'fallback_upgrade' => $fallbackUpgraded++,
                        default => $missingComputed++,
                    };

                    match ($result->prediction_source) {
                        'notebook_cache' => $notebookCacheCount++,
                        'live_model' => $liveModelCount++,
                        default => $fallbackCount++,
                    };
                } catch (\Throwable $e) {
                    $failed++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->line("\n");

        // Dashboard/report caches are keyed on SeniorDataVersion — MlService
        // never bumps it itself (see ProcessMlBatch::handle()). Bumped once
        // for the whole run, not per senior.
        SeniorDataVersion::bump();

        $this->info('=== Batch Summary ===');
        $this->table(
            ['Category', 'Count'],
            [
                ['Reused (valid, not recomputed)',         $reused],
                ['Stale → recomputed',                    $staleRecomputed],
                ['Version mismatch → recomputed',         $versionMismatchRecomputed],
                ['Missing → computed',                    $missingComputed],
                ['Fallback upgraded → recomputed',        $fallbackUpgraded],
                ['Failed',                                $failed],
                ['', ''],
                ['Result: notebook_cache',                $notebookCacheCount],
                ['Result: live_model',                    $liveModelCount],
                ['Result: fallback',                      $fallbackCount],
            ]
        );
        $this->line('');

        if ($strictMode) {
            return $this->runStrictCheck();
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function needsRecompute(?MlResult $existing, MlService $ml, bool $force, bool $staleOnly): bool
    {
        if ($force) {
            return true;
        }

        // No existing row — must compute
        if ($existing === null) {
            return true;
        }

        // Stale — must recompute
        if ($existing->is_stale) {
            return true;
        }

        // Model version changed — must recompute (notebook_cache is immune)
        if ($existing->prediction_source !== 'notebook_cache'
            && $existing->model_version !== MlService::MODEL_VERSION) {
            return true;
        }

        // Fallback result — upgrade to live_model when services are available
        if ($existing->prediction_source === 'fallback' && $ml->isPreprocessAvailable()) {
            return true;
        }

        // --stale-only: if we reach here, row is valid — skip it
        if ($staleOnly) {
            return false;
        }

        // Default: reuse valid rows
        return false;
    }

    private function recomputeReason(?MlResult $existing, MlService $ml, bool $force): string
    {
        if ($existing === null) {
            return 'missing';
        }
        if ($existing->is_stale) {
            return 'stale';
        }
        if ($existing->prediction_source === 'fallback' && $ml->isPreprocessAvailable()) {
            return 'fallback_upgrade';
        }
        if ($existing->model_version !== MlService::MODEL_VERSION) {
            return 'version_mismatch';
        }

        return 'force';
    }

    /**
     * After batch completes, verify all seed seniors have notebook_cache.
     */
    private function runStrictCheck(): int
    {
        $this->info('=== --strict-notebook-cache check ===');

        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->whereHas('seniorCitizen', fn ($q) => $q->active())
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        $nbCount = MlResult::whereIn('id', $latestIds)
            ->where('prediction_source', 'notebook_cache')
            ->count();

        $liveRows = MlResult::whereIn('id', $latestIds)
            ->where('prediction_source', 'live_model')
            ->with('seniorCitizen')
            ->get();

        $this->info("  notebook_cache : {$nbCount}");
        $this->info('  live_model     : '.$liveRows->count());
        $this->line('');

        if ($liveRows->isEmpty()) {
            $this->info('All seed seniors matched notebook cache. PASS');

            return self::SUCCESS;
        }

        $this->warn('The following seniors still have prediction_source = live_model:');
        $rows = $liveRows->map(fn ($r) => [
            $r->senior_citizen_id,
            $r->seniorCitizen?->first_name.' '.$r->seniorCitizen?->last_name,
            $r->seniorCitizen?->barangay,
            $r->seniorCitizen?->age,
            $r->prediction_source,
        ])->toArray();

        $this->table(['ID', 'Name', 'Barangay', 'Age', 'Source'], $rows);
        $this->line('');
        $this->error('--strict-notebook-cache FAILED: '.$liveRows->count().' live_model row(s) remain.');
        $this->line('Run: php artisan ml:repair-notebook-cache');

        return self::FAILURE;
    }
}
