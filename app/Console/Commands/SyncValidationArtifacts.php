<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Refreshes the in-app mirror of the notebook's evaluation artifacts so the
 * admin "System Validation" page (and the Health Groups KPIs) reflect the
 * current osca_output run.
 *
 *   - osca_output/reports/*.{csv,json}  → storage/app/ml_validation/reports/
 *   - osca_output/plots/<whitelist>.png → storage/app/ml_validation/plots/
 *   - regenerates python/models/cluster_eval_metrics.json from the chosen-K row
 */
class SyncValidationArtifacts extends Command
{
    protected $signature = 'ml:sync-validation {--k=4 : Chosen number of care groups to publish as the headline metrics}';

    protected $description = 'Sync model-evaluation reports/plots from osca_output into the app, and refresh cluster_eval_metrics.json';

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

    /** Per-recommendation detail copied from osca_output/predictions into the reports mirror. */
    private const PREDICTION_FILES = [
        'senior_recommendations_flat.csv',
    ];

    public function handle(): int
    {
        $source = realpath(base_path('../osca_output'));
        if (! $source || ! is_dir($source)) {
            $this->error('osca_output not found at '.base_path('../osca_output'));

            return self::FAILURE;
        }

        // 1 · Reports → storage mirror
        $reportsDst = storage_path('app/ml_validation/reports');
        @mkdir($reportsDst, 0775, true);
        $reportCount = 0;
        foreach (glob($source.'/reports/*.{csv,json}', GLOB_BRACE) ?: [] as $file) {
            if (@copy($file, $reportsDst.'/'.basename($file))) {
                $reportCount++;
            }
        }
        $this->info("Synced {$reportCount} report file(s) → storage/app/ml_validation/reports");

        // 2 · Whitelisted plots → storage mirror
        $plotsDst = storage_path('app/ml_validation/plots');
        @mkdir($plotsDst, 0775, true);
        $plotCount = 0;
        foreach (self::PLOTS as $name) {
            $src = $source.'/plots/'.$name.'.png';
            if (is_file($src) && @copy($src, $plotsDst.'/'.$name.'.png')) {
                $plotCount++;
            }
        }
        $this->info("Synced {$plotCount} plot(s) → storage/app/ml_validation/plots");

        // 2b · Per-recommendation detail (predictions/ → reports mirror)
        $predCount = 0;
        foreach (self::PREDICTION_FILES as $name) {
            $src = $source.'/predictions/'.$name;
            if (is_file($src) && @copy($src, $reportsDst.'/'.$name)) {
                $predCount++;
            }
        }
        $this->info("Synced {$predCount} prediction file(s) → storage/app/ml_validation/reports");

        // 3 · Regenerate cluster_eval_metrics.json from the chosen-K row
        if ($this->refreshClusterMetrics($reportsDst, (int) $this->option('k'))) {
            $this->info('Refreshed python/models/cluster_eval_metrics.json');
        } else {
            $this->warn('Could not refresh cluster_eval_metrics.json (clustering_evaluation.csv missing or K not found)');
        }

        return self::SUCCESS;
    }

    private function refreshClusterMetrics(string $reportsDir, int $k): bool
    {
        $csv = $reportsDir.'/clustering_evaluation.csv';
        if (! is_file($csv)) {
            return false;
        }
        $lines = preg_split('/\r\n|\r|\n/', trim(file_get_contents($csv)));
        $header = str_getcsv(array_shift($lines));
        $chosen = null;
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $row = array_combine($header, str_getcsv($line));
            if ((int) $row['K'] === $k) {
                $chosen = $row;
                break;
            }
        }
        if (! $chosen) {
            return false;
        }

        $payload = [
            'silhouette' => (float) $chosen['Silhouette'],
            'davies_bouldin' => (float) $chosen['Davies_Bouldin'],
            'calinski_harabasz' => (float) $chosen['Calinski_Harabasz'],
            'inertia' => null,
            'k_chosen' => $k,
            'generated_by' => 'ml:sync-validation',
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Write to both the live models dir and the storage mirror used as backup.
        $targets = [
            base_path('python/models/cluster_eval_metrics.json'),
            storage_path('app/ml_models/cluster_eval_metrics.json'),
        ];
        foreach ($targets as $target) {
            @mkdir(dirname($target), 0775, true);
            @file_put_contents($target, $json);
        }

        return true;
    }
}
