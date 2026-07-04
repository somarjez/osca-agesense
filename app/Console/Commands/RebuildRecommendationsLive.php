<?php

namespace App\Console\Commands;

use App\Models\QolSurvey;
use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Rebuild every senior's recommendations directly from the DATABASE through the
 * live engine (preprocess_service + catalog_recommender) — no notebook, no CSV.
 *
 * Cluster assignments and risk scores are NOT recomputed: each senior's frozen
 * risk_level / priority_flag from their existing latest ml_result drive the
 * urgency exactly as inference_service does. Only the recommendation rows are
 * replaced (soft-delete + insert on the same ml_result, so scopeCurrent()
 * returns the new set and history is preserved).
 */
class RebuildRecommendationsLive extends Command
{
    protected $signature = 'recommendations:rebuild-live
                            {--dry-run : Report what would change without writing}
                            {--senior=* : Limit to specific senior IDs}';

    protected $description = 'Rebuild recommendations from DB data via the live engine (risk/cluster stay frozen; no CSV involved).';

    public function handle(MlService $ml): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $onlyIds = array_map('intval', (array) $this->option('senior'));

        $this->info('=== recommendations:rebuild-live ===');
        $this->info('Mode : '.($isDryRun ? 'DRY-RUN' : 'LIVE'));

        $python = $ml->resolveLocalPythonExecutable();
        if ($python === null) {
            $this->error('No Python executable found (python/venv). Run setup first.');

            return self::FAILURE;
        }

        // ── 1. Collect payloads + frozen risk context ────────────────────────
        $seniors = SeniorCitizen::active()->with('latestMlResult')->get();
        $items = [];
        $skipped = [];
        $byId = [];

        foreach ($seniors as $senior) {
            if ($onlyIds && ! in_array($senior->id, $onlyIds, true)) {
                continue;
            }
            $result = $senior->latestMlResult;
            if ($result === null) {
                $skipped[] = $senior->id.' (no ml_result)';

                continue;
            }
            $survey = QolSurvey::find($result->qol_survey_id)
                ?? QolSurvey::where('senior_citizen_id', $senior->id)->orderByDesc('id')->first();
            if ($survey === null) {
                $skipped[] = $senior->id.' (no survey)';

                continue;
            }

            $items[] = [
                'senior_id' => $senior->id,
                'payload' => $ml->buildRawPayload($senior, $survey),
                'risk_level' => $result->risk_level,
                'priority_flag' => $result->priority_flag,
                'cluster_id' => $result->cluster_named_id ?? null,
            ];
            $byId[$senior->id] = ['senior' => $senior, 'ml' => $result];
        }

        $this->info('Seniors queued : '.count($items).($skipped ? '  (skipped '.count($skipped).')' : ''));
        if (! $items) {
            return self::SUCCESS;
        }

        // ── 2. Run the Python worker (production preprocess + engine) ────────
        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }
        $inPath = $tmpDir.'/rebuild_recs_input.json';
        $outPath = $tmpDir.'/rebuild_recs_output.json';
        file_put_contents($inPath, json_encode($items));

        $process = new Process(
            [$python, base_path('python/scripts/rebuild_recs_live.py'), $inPath, $outPath],
            base_path(),
            ['OSCA_BATCH_MODE' => '1'] + (getenv('ML_MODELS_PATH') ? [] : ['ML_MODELS_PATH' => base_path('python/models')])
        );
        $process->setTimeout(600);
        $process->run(fn ($type, $buf) => $this->output->write($buf));

        if (! $process->isSuccessful() && ! is_file($outPath)) {
            $this->error('Python worker failed: '.$process->getErrorOutput());

            return self::FAILURE;
        }

        $result = json_decode((string) file_get_contents($outPath), true) ?: [];
        $recsBySenior = $result['recommendations'] ?? [];
        $errors = $result['errors'] ?? [];
        foreach ($errors as $sid => $msg) {
            $this->warn("  senior {$sid}: {$msg}");
        }

        // ── 3. Replace recommendations on each senior's latest ml_result ─────
        $inserted = 0;
        $updatedSeniors = 0;
        foreach ($recsBySenior as $sid => $recs) {
            $ctx = $byId[(int) $sid] ?? null;
            if ($ctx === null) {
                continue;
            }
            $inserted += count($recs);
            $updatedSeniors++;
            if ($isDryRun) {
                continue;
            }

            DB::transaction(function () use ($ctx, $recs) {
                Recommendation::where('ml_result_id', $ctx['ml']->id)
                    ->where('senior_citizen_id', $ctx['senior']->id)
                    ->delete();

                $now = now();
                $rows = [];
                foreach ($recs as $rec) {
                    $docsNeeded = $rec['documents_needed'] ?? null;
                    $rows[] = [
                        'ml_result_id' => $ctx['ml']->id,
                        'senior_citizen_id' => $ctx['senior']->id,
                        'priority' => $rec['priority'],
                        'type' => $rec['type'],
                        'domain' => $rec['domain'] ?? null,
                        'category' => $rec['category'] ?? null,
                        'action' => $rec['action'] ?? '',
                        'urgency' => $rec['urgency'] ?? null,
                        'risk_level' => $rec['risk_level'] ?? null,
                        'notes' => $rec['reason'] ?? null,
                        'recommendation_code' => $rec['recommendation_code'] ?? null,
                        'service_provider' => $rec['service_provider'] ?? null,
                        'evidence_source' => $rec['evidence_source'] ?? null,
                        'apa_reference' => $rec['apa_reference'] ?? null,
                        'source_type' => $rec['source_type'] ?? null,
                        'trigger_summary' => $rec['trigger_summary'] ?? null,
                        'eligibility_basis' => $rec['eligibility_basis'] ?? null,
                        'documents_needed' => is_array($docsNeeded) ? json_encode($docsNeeded) : null,
                        'requires_human_validation' => (bool) ($rec['requires_human_validation'] ?? true),
                        'status' => 'pending',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($rows) {
                    Recommendation::insert($rows);
                }
            });
        }

        // ── 4. Report ─────────────────────────────────────────────────────────
        $this->line('');
        $this->info('Seniors rebuilt        : '.$updatedSeniors);
        $this->info(($isDryRun ? 'Recs that WOULD be set : ' : 'Recommendations inserted: ').$inserted);
        if (! $isDryRun) {
            $total = Recommendation::current()->count();
            $nullCodes = Recommendation::current()->whereNull('recommendation_code')->count();
            $this->info('Post-rebuild (current recs): total='.$total.'  null_code='.$nullCodes);
        }

        return empty($errors) ? self::SUCCESS : self::FAILURE;
    }
}
