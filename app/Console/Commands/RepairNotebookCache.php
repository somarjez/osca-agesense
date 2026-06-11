<?php

namespace App\Console\Commands;

use App\Models\MlResult;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Console\Command;

/**
 * ml:repair-notebook-cache
 *
 * Re-runs the scoring pipeline for every active senior that does NOT already
 * have prediction_source = notebook_cache.  After scoring, it reports:
 *
 *   - Which seniors were successfully matched to the CSV (notebook_cache)
 *   - Which seniors still fell through to live_model (with reason)
 *   - Final counts
 *
 * It does NOT write raw SQL.  It goes through MlService::runPipeline() so
 * every field (cluster, risk scores, recommendations, etc.) is populated
 * correctly by the normal pipeline, and persistResults() is called with
 * $force = true so it overwrites any stale live_model row.
 *
 * Usage:
 *   php artisan ml:repair-notebook-cache
 *   php artisan ml:repair-notebook-cache --dry-run          # show what would change
 *   php artisan ml:repair-notebook-cache --all              # re-score even notebook_cache rows
 */
class RepairNotebookCache extends Command
{
    protected $signature = 'ml:repair-notebook-cache
                            {--dry-run : Show which seniors would be re-scored without making changes}
                            {--all    : Re-score ALL active seniors, not just non-notebook_cache ones}';

    protected $description = 'Re-run the ML pipeline for seniors not matched to the notebook CSV and report results.';

    public function handle(MlService $ml): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $doAll = (bool) $this->option('all');

        set_time_limit(0);

        // ── 1. Collect seniors to process ────────────────────────────────────
        $query = SeniorCitizen::active()->with('latestQolSurvey', 'latestMlResult');

        if (! $doAll) {
            // Only seniors whose latest result is NOT notebook_cache (or have no result yet)
            $query->where(function ($q) {
                $q->whereDoesntHave('mlResults')
                    ->orWhereHas('latestMlResult', fn ($q2) => $q2->where('prediction_source', '!=', 'notebook_cache')
                    );
            });
        }

        $seniors = $query->get();

        $this->info('=== ml:repair-notebook-cache ===');
        $this->info('Mode     : '.($isDryRun ? 'DRY-RUN' : 'LIVE'));
        $this->info('Scope    : '.($doAll ? 'All active seniors' : 'Non-notebook_cache seniors only'));
        $this->info('Seniors  : '.$seniors->count());
        $this->line('');

        if ($seniors->isEmpty()) {
            $this->info('Nothing to repair — all active seniors already have notebook_cache results.');

            return self::SUCCESS;
        }

        // ── 2. Process each senior ────────────────────────────────────────────
        $repaired = [];   // Successfully matched to CSV
        $missed = [];   // Still fell through to live_model or fallback
        $noSurvey = [];   // Skipped — no QoL survey

        $bar = $this->output->createProgressBar($seniors->count());
        $bar->start();

        foreach ($seniors as $senior) {
            $bar->advance();

            $survey = $senior->latestQolSurvey;
            if ($survey === null) {
                $noSurvey[] = [
                    'id' => $senior->id,
                    'name' => $senior->first_name.' '.$senior->last_name,
                    'barangay' => $senior->barangay,
                    'age' => $senior->age,
                    'reason' => 'No QoL survey found',
                ];

                continue;
            }

            if ($isDryRun) {
                // In dry-run, just note who would be processed — don't actually call pipeline
                $missed[] = [
                    'id' => $senior->id,
                    'name' => $senior->first_name.' '.$senior->last_name,
                    'barangay' => $senior->barangay,
                    'age' => $senior->age,
                    'source' => $senior->latestMlResult?->prediction_source ?? 'none',
                    'reason' => 'dry-run: would be re-scored',
                ];

                continue;
            }

            try {
                // Force=true overrides the notebook_cache protection guard in persistResults()
                // so even a stale live_model row gets replaced with the correct notebook_cache row.
                $result = $ml->runPipelineForRepair($senior, $survey);

                $source = $result->prediction_source ?? 'unknown';
                if ($source === 'notebook_cache') {
                    $repaired[] = [
                        'id' => $senior->id,
                        'name' => $senior->first_name.' '.$senior->last_name,
                        'barangay' => $senior->barangay,
                        'age' => $senior->age,
                    ];
                } else {
                    $missed[] = [
                        'id' => $senior->id,
                        'name' => $senior->first_name.' '.$senior->last_name,
                        'barangay' => $senior->barangay,
                        'age' => $senior->age,
                        'source' => $source,
                        'reason' => 'CSV match failed (name/barangay/age mismatch)',
                    ];
                }
            } catch (\Throwable $e) {
                $missed[] = [
                    'id' => $senior->id,
                    'name' => $senior->first_name.' '.$senior->last_name,
                    'barangay' => $senior->barangay,
                    'age' => $senior->age,
                    'source' => 'error',
                    'reason' => 'Pipeline error: '.$e->getMessage(),
                ];
            }
        }

        $bar->finish();
        $this->line("\n");

        // ── 3. Report ─────────────────────────────────────────────────────────
        if (! $isDryRun && ! empty($repaired)) {
            $this->info('REPAIRED — matched to notebook CSV ('.count($repaired).'):');
            $headers = ['ID', 'Name', 'Barangay', 'Age'];
            $rows = array_map(
                fn ($r) => [$r['id'], $r['name'], $r['barangay'], $r['age']],
                $repaired
            );
            $this->table($headers, $rows);
            $this->line('');
        }

        if (! empty($noSurvey)) {
            $this->warn('SKIPPED — no QoL survey ('.count($noSurvey).'):');
            $this->table(
                ['ID', 'Name', 'Barangay', 'Age', 'Reason'],
                array_map(fn ($r) => [$r['id'], $r['name'], $r['barangay'], $r['age'], $r['reason']], $noSurvey)
            );
            $this->line('');
        }

        if (! empty($missed)) {
            $this->warn(
                ($isDryRun ? 'WOULD PROCESS' : 'STILL MISMATCHED').
                ' ('.count($missed).'):'
            );
            $this->table(
                ['ID', 'Name', 'Barangay', 'Age', 'Source', 'Reason'],
                array_map(
                    fn ($r) => [
                        $r['id'], $r['name'], $r['barangay'], $r['age'],
                        $r['source'] ?? '', $r['reason'] ?? '',
                    ],
                    $missed
                )
            );
            $this->line('');
        }

        // ── 4. Final summary ──────────────────────────────────────────────────
        $this->info('=== SUMMARY ===');
        if (! $isDryRun) {
            $this->info('  Repaired (notebook_cache) : '.count($repaired));
            $this->info('  Still mismatched          : '.count($missed));
            $this->info('  Skipped (no survey)       : '.count($noSurvey));
        } else {
            $this->info('  Would re-score : '.count($missed));
            $this->info('  Skipped        : '.count($noSurvey));
        }
        $this->line('');

        // Verify final DB state
        if (! $isDryRun) {
            $nbCount = MlResult::whereHas(
                'seniorCitizen', fn ($q) => $q->where('status', 'active')->whereNull('deleted_at')
            )->whereIn('id', function ($sub) {
                $sub->selectRaw('MAX(ml_results.id)')
                    ->from('ml_results')
                    ->join('senior_citizens', 'senior_citizens.id', '=', 'ml_results.senior_citizen_id')
                    ->where('senior_citizens.status', 'active')
                    ->whereNull('senior_citizens.deleted_at')
                    ->groupBy('ml_results.senior_citizen_id');
            })->where('prediction_source', 'notebook_cache')->count();

            $liveCount = MlResult::whereHas(
                'seniorCitizen', fn ($q) => $q->where('status', 'active')->whereNull('deleted_at')
            )->whereIn('id', function ($sub) {
                $sub->selectRaw('MAX(ml_results.id)')
                    ->from('ml_results')
                    ->join('senior_citizens', 'senior_citizens.id', '=', 'ml_results.senior_citizen_id')
                    ->where('senior_citizens.status', 'active')
                    ->whereNull('senior_citizens.deleted_at')
                    ->groupBy('ml_results.senior_citizen_id');
            })->where('prediction_source', 'live_model')->count();

            $this->info("  DB: notebook_cache : {$nbCount}");
            $this->info("  DB: live_model     : {$liveCount}");

            if ($liveCount === 0) {
                $this->info('  All seed seniors now have notebook_cache results.');

                return self::SUCCESS;
            } else {
                $this->warn("  {$liveCount} senior(s) still have live_model — check mismatched list above.");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
