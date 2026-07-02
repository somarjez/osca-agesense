<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Exports a de-identified copy of the System Validation artifacts to a
 * git-tracked folder so the admin "System Validation" page can populate on
 * any machine after a plain `git pull` — no notebook, no osca_output/, no
 * PII-bearing storage mirror required.
 *
 * Source (first one found wins):
 *   - storage/app/ml_validation/{reports,plots}/   (populated by ml:sync-validation)
 *   - ../osca_output/{reports,plots}/              (raw notebook output)
 *
 * Destination (tracked in git — NOT the gitignored ml_validation/ path):
 *   - storage/app/ml_validation_public/reports/
 *   - storage/app/ml_validation_public/plots/
 *
 * Two report files carry a senior_id/name/barangay column but the validation
 * page only ever aggregates a handful of the remaining columns — those are
 * rewritten with only the safe columns kept. The per-device
 * cache_vs_live_model_comparison.csv (individual-level cluster/risk
 * concordance) is never exported; that pillar simply stays hidden on a
 * pulled clone (ModelValidation::fidelity() already degrades gracefully).
 *
 * A PII guard scans every file this command is about to write and refuses to
 * write (and deletes on failure) anything whose CSV header or JSON keys look
 * identifying, as defense-in-depth against the whitelist itself being wrong.
 */
class ExportValidationArtifacts extends Command
{
    protected $signature = 'ml:export-validation';

    protected $description = 'Export a de-identified copy of the System Validation artifacts into a git-tracked folder';

    /** Aggregate report files with no per-senior identifiers — copied verbatim. */
    private const SAFE_REPORTS = [
        'clustering_evaluation.csv',
        'cluster_stability_results.csv',
        'feature_ablation_results.csv',
        'cluster_metadata.json',
        'cluster_summary.csv',
        'ml_risk_cv_scores.csv',
        'risk_mae_rmse.csv',
        'rule_vs_ml_correlation.csv',
        'tuned_model_results.csv',
        'calibration_check.csv',
        'leakage_check_report.json',
        'risk_level_validation_summary.json',
        'risk_level_distribution.csv',
        'threshold_comparison_results.csv',
        'feature_significance_kruskal.csv',
        'vif_final.csv',
        'rule_sanity_check.csv',
        'scoring_weights_documentation.json',
    ];

    /** Plots surfaced by the validation page (matches ModelValidationController::PLOTS). */
    private const PLOTS = [
        'k_selection_umap',
        'silhouette_plot',
        'cluster_umap_scatter',
        'cluster_radar',
        'cluster_heatmap',
        'section_scores_by_cluster',
        'section_score_distributions',
        'umap_wellbeing',
        'risk_distribution',
        'risk_level_confusion_matrix',
        'gbr_feature_importance',
        'calibration_reliability',
    ];

    /** Per-senior report files: keep only the columns the page actually aggregates. */
    private const SCRUBBED_REPORTS = [
        'recommendation_summary.csv' => ['risk_level', 'cluster_name', 'n_top_recs', 'n_all_recs', 'top_categories'],
        'senior_recommendations_flat.csv' => ['category', 'priority', 'requires_human_validation', 'evidence_source'],
    ];

    /** Column / key names that must never appear in an exported file. */
    private const FORBIDDEN_CSV_COLUMNS = ['name', 'full_name', 'senior_id', 'barangay'];

    private const FORBIDDEN_JSON_KEYS = ['full_name', 'senior_id', 'barangay'];

    public function handle(): int
    {
        [$reportsSrc, $plotsSrc] = $this->resolveSources();
        if (! $reportsSrc) {
            $this->error('No validation source found. Run `php artisan ml:sync-validation` first (or ensure ../osca_output/reports exists).');

            return self::FAILURE;
        }

        $reportsDst = storage_path('app/ml_validation_public/reports');
        $plotsDst = storage_path('app/ml_validation_public/plots');
        @mkdir($reportsDst, 0775, true);
        @mkdir($plotsDst, 0775, true);

        $written = [];
        $failed = false;

        // 1 · Safe reports — copied verbatim.
        $count = 0;
        foreach (self::SAFE_REPORTS as $file) {
            $src = $reportsSrc.'/'.$file;
            if (! is_file($src)) {
                continue;
            }
            $dst = $reportsDst.'/'.$file;
            if (! @copy($src, $dst)) {
                continue;
            }
            $written[] = $dst;
            $count++;
        }
        $this->info("Exported {$count} safe report file(s).");

        // 2 · Scrubbed reports — rewritten with only the whitelisted columns.
        $scrubCount = 0;
        foreach (self::SCRUBBED_REPORTS as $file => $keepColumns) {
            $src = $reportsSrc.'/'.$file;
            if (! is_file($src)) {
                continue;
            }
            $dst = $reportsDst.'/'.$file;
            if ($this->scrubCsv($src, $dst, $keepColumns)) {
                $written[] = $dst;
                $scrubCount++;
            } else {
                $this->warn("Could not scrub {$file} (missing expected columns) — skipped.");
            }
        }
        $this->info("Exported {$scrubCount} de-identified report file(s).");

        // 3 · Plots.
        $plotCount = 0;
        if ($plotsSrc) {
            foreach (self::PLOTS as $name) {
                $src = $plotsSrc.'/'.$name.'.png';
                if (! is_file($src)) {
                    continue;
                }
                $dst = $plotsDst.'/'.$name.'.png';
                if (@copy($src, $dst)) {
                    $written[] = $dst;
                    $plotCount++;
                }
            }
        }
        $this->info("Exported {$plotCount} plot(s).");

        // 4 · PII guard — verify every written file before declaring success.
        foreach ($written as $path) {
            $violation = $this->findPiiViolation($path);
            if ($violation !== null) {
                @unlink($path);
                $this->error("Refusing to keep {$path}: contains forbidden field '{$violation}'. Deleted.");
                $failed = true;
            }
        }

        if ($failed) {
            $this->error('Export aborted: one or more files failed the PII guard. See errors above.');

            return self::FAILURE;
        }

        $this->info('Validation artifacts exported to storage/app/ml_validation_public/ — safe to `git add` and commit.');

        return self::SUCCESS;
    }

    /** @return array{0: ?string, 1: ?string} [reportsDir, plotsDir] */
    private function resolveSources(): array
    {
        $mirrorReports = storage_path('app/ml_validation/reports');
        $mirrorPlots = storage_path('app/ml_validation/plots');
        if (is_dir($mirrorReports)) {
            return [$mirrorReports, is_dir($mirrorPlots) ? $mirrorPlots : null];
        }

        $osca = realpath(base_path('../osca_output'));
        if ($osca && is_dir($osca.'/reports')) {
            return [$osca.'/reports', is_dir($osca.'/plots') ? $osca.'/plots' : null];
        }

        return [null, null];
    }

    private function scrubCsv(string $src, string $dst, array $keepColumns): bool
    {
        $raw = file_get_contents($src);
        if ($raw === false) {
            return false;
        }
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        if (! $lines || count($lines) < 1) {
            return false;
        }
        $header = str_getcsv(array_shift($lines));
        $keepIdx = [];
        foreach ($keepColumns as $col) {
            $idx = array_search($col, $header, true);
            if ($idx === false) {
                return false;
            }
            $keepIdx[] = $idx;
        }

        $out = fopen($dst, 'w');
        if ($out === false) {
            return false;
        }
        fputcsv($out, $keepColumns);
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $row = str_getcsv($line);
            if (count($row) !== count($header)) {
                continue;
            }
            fputcsv($out, array_map(fn ($i) => $row[$i] ?? '', $keepIdx));
        }
        fclose($out);

        return true;
    }

    /** Returns the offending field name, or null if the file is clean. */
    private function findPiiViolation(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            $handle = fopen($path, 'r');
            if ($handle === false) {
                return null;
            }
            $header = fgetcsv($handle);
            fclose($handle);
            if (! $header) {
                return null;
            }
            foreach ($header as $col) {
                $col = strtolower(trim($col));
                if (in_array($col, self::FORBIDDEN_CSV_COLUMNS, true)) {
                    return $col;
                }
            }

            return null;
        }

        if ($ext === 'json') {
            $data = json_decode((string) file_get_contents($path), true);
            if (! is_array($data)) {
                return null;
            }

            return $this->findForbiddenJsonKey($data);
        }

        return null;
    }

    private function findForbiddenJsonKey(array $data): ?string
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_JSON_KEYS, true)) {
                return strtolower($key);
            }
            if (is_array($value)) {
                $found = $this->findForbiddenJsonKey($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
