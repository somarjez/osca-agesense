<?php

namespace App\Console\Commands;

use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-import recommendations from the notebook's senior_recommendations_flat.csv
 * onto each senior's EXISTING latest ml_result, WITHOUT re-running clustering,
 * risk scoring, or the ML pipeline. This refreshes only the recommendations
 * (e.g. after a recommendation-engine fix) while the validated cluster_id and
 * risk scores stay frozen — ml_results rows are never modified.
 *
 * Matching is by normalized name + barangay (the CSV's senior_id is a notebook
 * row index, not the DB id). Existing recommendations on the target ml_result
 * are soft-deleted and replaced with the CSV rows, so scopeCurrent() returns the
 * new set while history is preserved.
 */
class ReimportRecommendations extends Command
{
    protected $signature = 'recommendations:reimport-from-csv
                            {--path= : Path to senior_recommendations_flat.csv (default: ../osca_output/predictions/...)}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Re-import recommendations from the notebook flat CSV onto existing ml_results (cluster/risk frozen).';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $path = $this->option('path')
            ?: base_path('../osca_output/predictions/senior_recommendations_flat.csv');

        if (! is_file($path)) {
            $this->error("CSV not found: {$path}");

            return self::FAILURE;
        }

        $this->info('=== recommendations:reimport-from-csv ===');
        $this->info('Mode : '.($isDryRun ? 'DRY-RUN' : 'LIVE'));
        $this->info('CSV  : '.realpath($path));

        // ── 1. Load + group CSV rows by normalized (name | barangay) ────────────
        [$groups, $csvRowCount] = $this->loadCsv($path);
        $this->info('CSV  : '.$csvRowCount.' rows across '.count($groups).' seniors');
        $this->line('');

        // ── 2. Walk active seniors, match, replace recs on latest ml_result ─────
        $seniors = SeniorCitizen::query()->with('latestMlResult')->get();

        $matched = 0;
        $unmatched = [];
        $noResult = [];
        $recsInserted = 0;

        $bar = $this->output->createProgressBar($seniors->count());
        $bar->start();

        foreach ($seniors as $senior) {
            $bar->advance();

            $ml = $senior->latestMlResult;
            if ($ml === null) {
                $noResult[] = $senior->first_name.' '.$senior->last_name;

                continue;
            }

            $key = $this->normKey($senior->first_name.' '.$senior->last_name, $senior->barangay);
            $rows = $groups[$key] ?? null;
            if ($rows === null) {
                $unmatched[] = $senior->first_name.' '.$senior->last_name.' ('.$senior->barangay.')';

                continue;
            }

            $matched++;
            $recsInserted += count($rows);

            if ($isDryRun) {
                continue;
            }

            DB::transaction(function () use ($senior, $ml, $rows) {
                // Soft-delete the current recs on this ml_result, then insert anew.
                Recommendation::where('ml_result_id', $ml->id)
                    ->where('senior_citizen_id', $senior->id)
                    ->delete();

                $now = now();
                $insert = [];
                $priority = 1;
                foreach ($rows as $r) {
                    $docs = trim((string) ($r['documents_needed'] ?? ''));
                    $insert[] = [
                        'ml_result_id' => $ml->id,
                        'senior_citizen_id' => $senior->id,
                        'priority' => $priority++,
                        'type' => 'domain',
                        'domain' => $this->nullable($r['domain'] ?? null),
                        'category' => strtolower(trim((string) ($r['category'] ?? 'general'))) ?: 'general',
                        'action' => (string) ($r['recommendation'] ?? ''),
                        'urgency' => $this->mapUrgency($r['priority'] ?? '', $r['risk_level'] ?? ''),
                        'risk_level' => $this->mapRiskLevel($r['risk_level'] ?? ''),
                        'notes' => $this->nullable($r['reason'] ?? null),
                        'recommendation_code' => $this->nullable($r['recommendation_code'] ?? null),
                        'service_provider' => $this->nullable($r['service_provider'] ?? null),
                        'evidence_source' => $this->nullable($r['evidence_source'] ?? null),
                        'apa_reference' => $this->nullable($r['apa_reference'] ?? null),
                        'source_type' => $this->nullable($r['source_type'] ?? null),
                        'trigger_summary' => $this->nullable($r['trigger_summary'] ?? null),
                        'documents_needed' => ($docs !== '' && $docs !== '[]') ? $docs : null,
                        'requires_human_validation' => (int) ($r['requires_human_validation'] ?? 1) === 1,
                        'status' => 'pending',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                Recommendation::insert($insert);
            });
        }

        $bar->finish();
        $this->line("\n");

        // ── 3. Report ───────────────────────────────────────────────────────────
        $this->info('Matched seniors        : '.$matched.' / '.$seniors->count());
        $this->info(($isDryRun ? 'Recs that WOULD be set  : ' : 'Recommendations inserted: ').$recsInserted);
        if ($noResult) {
            $this->warn('Seniors with no ml_result ('.count($noResult).'): '.implode('; ', array_slice($noResult, 0, 10)));
        }
        if ($unmatched) {
            $this->warn('UNMATCHED seniors ('.count($unmatched).'):');
            foreach (array_slice($unmatched, 0, 20) as $u) {
                $this->line('   - '.$u);
            }
        }

        if (! $isDryRun) {
            $nullCodes = Recommendation::current()->whereNull('recommendation_code')->count();
            $withTrigger = Recommendation::current()->whereNotNull('trigger_summary')->count();
            $total = Recommendation::current()->count();
            $this->line('');
            $this->info('Post-import (current recs): total='.$total
                .'  null_code='.$nullCodes.'  with_trigger_summary='.$withTrigger);
        }

        return self::SUCCESS;
    }

    /** @return array{0: array<string, array<int, array<string,string>>>, 1: int} */
    private function loadCsv(string $path): array
    {
        $fp = fopen($path, 'r');
        $header = fgetcsv($fp);
        // strip UTF-8 BOM from first header cell
        if (! empty($header)) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }
        $groups = [];
        $count = 0;
        while (($line = fgetcsv($fp)) !== false) {
            if ($line === [null] || $line === false) {
                continue;
            }
            $row = @array_combine($header, $line);
            if ($row === false) {
                continue;
            }
            $count++;
            $key = $this->normKey($row['name'] ?? '', $row['barangay'] ?? '');
            $groups[$key][] = $row;
        }
        fclose($fp);

        return [$groups, $count];
    }

    private function normKey(string $name, string $barangay): string
    {
        return $this->norm($name).'|'.$this->norm($barangay);
    }

    /** Normalize a Filipino name/barangay to [a-z0-9] (ñ→n, accents stripped). */
    private function norm(string $s): string
    {
        $s = str_replace(['ñ', 'Ñ'], 'n', $s);
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) {
            $s = $t;
        }

        return preg_replace('/[^a-z0-9]+/', '', strtolower($s));
    }

    private function nullable(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    private function mapUrgency(string $priorityLabel, string $riskLevel): ?string
    {
        $p = strtolower(trim($priorityLabel));
        if (in_array($p, ['immediate', 'urgent', 'planned', 'maintenance'], true)) {
            return $p;
        }

        return match (strtoupper(trim($riskLevel))) {
            'LOW' => 'maintenance',
            default => 'planned',
        };
    }

    private function mapRiskLevel(string $riskLevel): ?string
    {
        $r = strtolower(trim($riskLevel));
        // Fold critical→high: live app is 3-level display; urgency is surfaced via
        // priority_flag='urgent' (wired in MlService::persistResults) not a 4th level.
        if ($r === 'critical') {
            $r = 'high';
        }

        return in_array($r, ['low', 'moderate', 'high'], true) ? $r : null;
    }
}
