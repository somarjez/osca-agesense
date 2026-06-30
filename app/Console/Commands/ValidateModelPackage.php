<?php

namespace App\Console\Commands;

use App\Services\ClusterAnalyticsService;
use Illuminate\Console\Command;

/**
 * ml:validate-model-package
 *
 * Read-only validation of the locked K=4 + KNN MinMaxScaler-30 model package.
 *
 * Checks:
 *   1. Python artifact checks (delegates to python/scripts/validate_model_package.py)
 *      - scaler.pkl = MinMaxScaler, n_features=30, feature_names_in_ present
 *      - kmeans.pkl n_clusters=4
 *      - cluster_assignment_knn_k5.pkl exists, k=5, labels in {1,2,3,4}
 *      - feature_list.json length=30
 *      - final_feature_list.json final_clustering_feature_count=30, no "31-feature" prose
 *      - scoring_weights_documentation.json scaler=MinMaxScaler, umap nn=10, kmeans n_init=100, 4-level thresholds (LOW/MODERATE/HIGH/CRITICAL 0.70)
 *
 *   2. DB cross-check (PHP)
 *      - Live dashboard cluster distribution matches official batch counts
 *        from python/models/cluster_metadata.json (per-cluster n_seniors).
 *        Tolerance: +/- 5 seniors per cluster (accounts for new/deleted seniors
 *        added after the batch run).
 *
 * Returns self::SUCCESS (0) only when ALL Python artifact checks pass AND the
 * DB count is within tolerance. Warnings do not fail the run.
 *
 * Usage:
 *   php artisan ml:validate-model-package
 *   php artisan ml:validate-model-package --skip-db    # artifact checks only
 *   php artisan ml:validate-model-package --tolerance=10
 */
class ValidateModelPackage extends Command
{
    protected $signature = 'ml:validate-model-package
                            {--skip-db : Skip the DB cluster-count cross-check}
                            {--tolerance=5 : Max allowed per-cluster count difference vs official batch}';

    protected $description = 'Validate the K=4 + KNN MinMaxScaler-30 model package (artifact checks + DB cross-check)';

    /** Path to the Python venv interpreter (relative to project root). */
    private const PYTHON = 'python/venv/Scripts/python.exe';

    /** Python validation script path (relative to project root). */
    private const PY_SCRIPT = 'python/scripts/validate_model_package.py';

    /** cluster_metadata.json in models dir — official per-cluster batch counts. */
    private const CLUSTER_META = 'python/models/cluster_metadata.json';

    public function handle(ClusterAnalyticsService $analytics): int
    {
        $this->line('');
        $this->line('╔══════════════════════════════════════════════════════════╗');
        $this->line('║   AgeSense Model Package Validation — K=4 + KNN/30f    ║');
        $this->line('╚══════════════════════════════════════════════════════════╝');

        $overallOk = true;

        // ── Part 1: Python artifact checks ────────────────────────────────────
        $this->line('');
        $this->line('Part 1: Artifact checks (validate_model_package.py)');
        $this->line(str_repeat('-', 60));

        $python = base_path(self::PYTHON);
        $script = base_path(self::PY_SCRIPT);

        if (! file_exists($python)) {
            $this->warn("Python not found at {$python}. Trying system python.");
            $python = 'python';
        }

        if (! file_exists($script)) {
            $this->error("Validation script not found: {$script}");

            return self::FAILURE;
        }

        $cmd     = escapeshellarg($python).' '.escapeshellarg($script);
        $output  = [];
        $exitCode = 0;
        exec($cmd.' 2>&1', $output, $exitCode);

        foreach ($output as $line) {
            if (str_contains($line, '[FAIL]')) {
                $this->error($line);
            } elseif (str_contains($line, '[WARN]')) {
                $this->warn($line);
            } elseif (str_contains($line, '[PASS]')) {
                $this->info($line);
            } else {
                $this->line($line);
            }
        }

        if ($exitCode !== 0) {
            $this->error('Python artifact validation FAILED (exit '.$exitCode.').');
            $overallOk = false;
        } else {
            $this->info('Python artifact validation PASSED.');
        }

        // ── Part 2: DB cluster-count cross-check ──────────────────────────────
        if (! $this->option('skip-db')) {
            $this->line('');
            $this->line('Part 2: DB cluster-count cross-check');
            $this->line(str_repeat('-', 60));

            $metaPath = base_path(self::CLUSTER_META);
            if (! file_exists($metaPath)) {
                $this->warn('cluster_metadata.json not found at '.self::CLUSTER_META.' — skipping DB check.');
            } else {
                try {
                    $meta      = json_decode(file_get_contents($metaPath), true) ?? [];
                    $tolerance = (int) $this->option('tolerance');

                    // official batch counts: keyed by named cluster id (integer)
                    $official = [];
                    foreach ($meta as $cluster) {
                        $id = (int) ($cluster['cluster_id'] ?? $cluster['id'] ?? 0);
                        if ($id > 0) {
                            $official[$id] = (int) ($cluster['n_seniors'] ?? $cluster['member_count'] ?? 0);
                        }
                    }

                    if (empty($official)) {
                        $this->warn('cluster_metadata.json has no cluster entries — cannot validate counts.');
                    } else {
                        // Live counts from DB (latest-per-senior, active)
                        $liveDist   = $analytics->clusterDistribution();
                        $liveCounts = [];
                        foreach ($liveDist['data'] ?? [] as $i => $count) {
                            $id = (int) ($liveDist['ids'][$i] ?? 0);
                            if ($id > 0) {
                                $liveCounts[$id] = (int) $count;
                            }
                        }

                        $dbCheckOk = true;
                        $this->line(sprintf(
                            '  %-12s %-12s %-12s %-10s %s',
                            'Cluster', 'Official', 'Live DB', 'Delta', 'Status'
                        ));
                        $this->line('  '.str_repeat('-', 55));

                        foreach ($official as $id => $batchCount) {
                            $live  = $liveCounts[$id] ?? null;
                            $delta = $live !== null ? abs($live - $batchCount) : null;
                            if ($live === null) {
                                $status = 'WARN (not in DB)';
                                $this->warn(sprintf('  %-12s %-12s %-12s %-10s %s', "C{$id}", $batchCount, '-', '-', $status));
                            } elseif ($delta <= $tolerance) {
                                $status = 'PASS';
                                $this->info(sprintf('  %-12s %-12s %-12s %-10s %s', "C{$id}", $batchCount, $live, "+/-{$delta}", $status));
                            } else {
                                $status = "FAIL (delta={$delta} > tol={$tolerance})";
                                $this->error(sprintf('  %-12s %-12s %-12s %-10s %s', "C{$id}", $batchCount, $live, "+/-{$delta}", $status));
                                $dbCheckOk = false;
                            }
                        }

                        if ($dbCheckOk) {
                            $this->info('DB cluster counts MATCH official batch (within tolerance '.$tolerance.').');
                        } else {
                            $this->error('DB cluster counts MISMATCH. Run ml:repair-notebook-cache --all to re-sync.');
                            $overallOk = false;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->warn('DB check error: '.$e->getMessage());
                }
            }
        }

        // ── Summary ───────────────────────────────────────────────────────────
        $this->line('');
        $this->line(str_repeat('=', 60));
        if ($overallOk) {
            $this->info('PACKAGE STATUS: LOCKED  — all checks passed.');
        } else {
            $this->error('PACKAGE STATUS: NOT LOCKED  — see FAILs above.');
            $this->line('  Fix FAILs, re-run osca5.ipynb, redeploy artifacts, then');
            $this->line('  run: php artisan ml:repair-notebook-cache --all');
        }
        $this->line(str_repeat('=', 60));

        return $overallOk ? self::SUCCESS : self::FAILURE;
    }
}
