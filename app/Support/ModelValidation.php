<?php

namespace App\Support;

/**
 * Reads the model-evaluation artifacts emitted by the notebook (osca5.ipynb)
 * and shapes them for the admin "System Validation" report.
 *
 * Source of truth is the in-app mirror at storage/app/ml_validation/reports/
 * (populated by `php artisan ml:sync-validation`), with a fallback to the
 * original osca_output/reports/ one level above the Laravel root.
 *
 * All figures are presented in the live THREE-level risk system
 * (LOW / MODERATE / HIGH + Priority Flag). Where a legacy artifact still
 * carries a fourth CRITICAL band, it is folded into HIGH here.
 */
class ModelValidation
{
    /** Operational risk levels (CRITICAL retired → folded into HIGH + Priority Flag). */
    public const LEVELS = ['LOW', 'MODERATE', 'HIGH'];

    // ── File access ─────────────────────────────────────────────────────

    private function raw(string $file): ?string
    {
        $mirror = storage_path('app/ml_validation/reports/'.$file);
        if (is_file($mirror)) {
            return file_get_contents($mirror);
        }
        $fallback = base_path('../osca_output/reports/'.$file);
        if (is_file($fallback)) {
            return file_get_contents($fallback);
        }

        return null;
    }

    private function csv(string $file): array
    {
        $raw = $this->raw($file);
        if ($raw === null) {
            return [];
        }
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        if (! $lines || count($lines) < 2) {
            return [];
        }
        $header = str_getcsv(array_shift($lines));
        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $vals = str_getcsv($line);
            if (count($vals) !== count($header)) {
                continue;
            }
            $rows[] = array_combine($header, $vals);
        }

        return $rows;
    }

    private function json(string $file): ?array
    {
        $raw = $this->raw($file);

        return $raw !== null ? json_decode($raw, true) : null;
    }

    /** Last-modified timestamp of the evaluation run (drives the "generated" stamp). */
    public function generatedAt(): ?\DateTimeInterface
    {
        foreach (['clustering_evaluation.csv', 'risk_mae_rmse.csv'] as $file) {
            $mirror = storage_path('app/ml_validation/reports/'.$file);
            if (is_file($mirror)) {
                return (new \DateTimeImmutable)->setTimestamp(filemtime($mirror));
            }
        }

        return null;
    }

    public function available(): bool
    {
        return $this->raw('clustering_evaluation.csv') !== null;
    }

    // ── Pillar 1 · Care-group discovery (clustering) ────────────────────

    public function clustering(): array
    {
        $headline = ClusterMetrics::load();
        $kChosen = (int) ($headline['k_chosen'] ?? 4);

        // K-selection sweep (K = 2..6).
        $sweep = [];
        foreach ($this->csv('clustering_evaluation.csv') as $r) {
            $sweep[] = [
                'k' => (int) $r['K'],
                'silhouette' => (float) $r['Silhouette'],
                'davies_bouldin' => (float) $r['Davies_Bouldin'],
                'calinski_harabasz' => (float) $r['Calinski_Harabasz'],
            ];
        }
        $chosen = collect($sweep)->firstWhere('k', $kChosen) ?: ($sweep[0] ?? null);

        // Best K on each criterion — lets the view explain *why* honestly.
        $bestSil = collect($sweep)->sortByDesc('silhouette')->first();
        $bestDb = collect($sweep)->sortBy('davies_bouldin')->first();
        $bestCh = collect($sweep)->sortByDesc('calinski_harabasz')->first();

        // Which criteria the chosen K actually wins (data-adaptive — stays true
        // whether or not the chosen K dominates every metric).
        $winsOn = [];
        if ($kChosen === ($bestSil['k'] ?? null)) {
            $winsOn[] = 'separation (Silhouette)';
        }
        if ($kChosen === ($bestDb['k'] ?? null)) {
            $winsOn[] = 'distinctness (Davies–Bouldin)';
        }
        if ($kChosen === ($bestCh['k'] ?? null)) {
            $winsOn[] = 'compactness (Calinski–Harabasz)';
        }

        // Seed stability — identical scores across seeds = deterministic.
        $stab = $this->csv('cluster_stability_results.csv');
        $distinctSil = collect($stab)->pluck('Silhouette')->unique()->count();
        $stability = [
            'seeds' => count($stab),
            'distinct' => $distinctSil,
            'stable' => count($stab) > 0 && $distinctSil === 1,
        ];

        // Feature ablation — redundant features removed lifted separation.
        $ablation = [];
        foreach ($this->csv('feature_ablation_results.csv') as $r) {
            $ablation[] = [
                'condition' => $r['Condition'],
                'n_features' => (int) $r['N_Features'],
                'silhouette' => (float) $r['Silhouette'],
            ];
        }

        // The four care groups with sizes + interpretations.
        $groups = [];
        $meta = $this->json('cluster_metadata.json') ?? [];
        foreach ($meta as $g) {
            $groups[] = [
                'id' => (int) $g['id'],
                'name' => $g['name'],
                'n_seniors' => (int) $g['n_seniors'],
                'color' => $g['color'],
                'interpretation' => $g['interpretation'],
                'ic_level' => $g['ic_level'] ?? null,
                'env_level' => $g['env_level'] ?? null,
                'func_level' => $g['func_level'] ?? null,
            ];
        }
        usort($groups, fn ($a, $b) => $a['id'] <=> $b['id']);

        $silhouette = (float) ($chosen['silhouette'] ?? $headline['silhouette'] ?? 0);

        return [
            'k_chosen' => $kChosen,
            'chosen' => $chosen,
            'sweep' => $sweep,
            'best_silhouette_k' => $bestSil['k'] ?? null,
            'best_davies_k' => $bestDb['k'] ?? null,
            'best_calinski_k' => $bestCh['k'] ?? null,
            'chosen_wins' => $winsOn,
            'stability' => $stability,
            'ablation' => $ablation,
            'groups' => $groups,
            'verdicts' => [
                'separation' => $this->verdict($silhouette >= 0.45, 'Strong', 'Adequate'),
                'distinctness' => $this->verdict(($chosen['davies_bouldin'] ?? 99) < 1.0, 'Strong', 'Adequate'),
                'stability' => $this->verdict($stability['stable'], 'Deterministic', 'Variable'),
            ],
        ];
    }

    // ── Pillar 2 · Risk estimation (regression + classification) ────────

    public function riskRegression(): array
    {
        $domains = [
            'ic_risk' => 'Intrinsic Capacity',
            'env_risk' => 'Environment',
            'func_risk' => 'Functional Ability',
        ];

        $cv = [];
        foreach ($this->csv('ml_risk_cv_scores.csv') as $r) {
            $cv[$r['Target']] = [
                'gbr_r2' => (float) $r['GBR_CV_R2'],
                'gbr_mae' => (float) $r['GBR_CV_MAE'],
                'gbr_r2_std' => (float) $r['GBR_CV_R2_std'],
                'rfr_r2' => (float) $r['RFR_CV_R2'],
                'rfr_mae' => (float) $r['RFR_CV_MAE'],
            ];
        }

        $holdout = [];
        foreach ($this->csv('risk_mae_rmse.csv') as $r) {
            $holdout[$r['Target']][$r['Model']] = [
                'mae' => (float) $r['MAE'],
                'rmse' => (float) $r['RMSE'],
                'r2' => (float) $r['R2'],
            ];
        }

        $corr = [];
        foreach ($this->csv('rule_vs_ml_correlation.csv') as $r) {
            $corr[$r['Target']] = [
                'rho' => (float) $r['Rule_vs_GBR_rho'],
                'p' => (float) ($r['Rule_vs_GBR_p'] ?? 0),
                'rfr_rho' => (float) ($r['Rule_vs_RFR_rho'] ?? 0),
                'status' => $r['Status'],
            ];
        }

        // Tuned hyperparameters (deployed config) per target+model.
        $tuned = [];
        foreach ($this->csv('tuned_model_results.csv') as $r) {
            $tuned[$r['Target']][$r['Model']] = [
                'best_mae_cv' => (float) $r['Best_MAE_CV'],
                'params' => $this->pyDict($r['Params'] ?? ''),
            ];
        }

        // One consolidated per-domain row for the detailed evidence table.
        $table = [];
        foreach ($domains as $key => $name) {
            $table[$key] = [
                'name' => $name,
                'gbr' => [
                    'cv_r2' => $cv[$key]['gbr_r2'] ?? null,
                    'cv_r2_std' => $cv[$key]['gbr_r2_std'] ?? null,
                    'cv_mae' => $cv[$key]['gbr_mae'] ?? null,
                    'mae' => $holdout[$key]['GBR']['mae'] ?? null,
                    'rmse' => $holdout[$key]['GBR']['rmse'] ?? null,
                    'r2' => $holdout[$key]['GBR']['r2'] ?? null,
                    'params' => $tuned[$key]['GBR']['params'] ?? [],
                ],
                'rfr' => [
                    'cv_r2' => $cv[$key]['rfr_r2'] ?? null,
                    'cv_mae' => $cv[$key]['rfr_mae'] ?? null,
                    'mae' => $holdout[$key]['RFR']['mae'] ?? null,
                    'rmse' => $holdout[$key]['RFR']['rmse'] ?? null,
                    'r2' => $holdout[$key]['RFR']['r2'] ?? null,
                ],
            ];
        }

        $calibration = [];
        foreach ($this->csv('calibration_check.csv') as $r) {
            $calibration[] = [
                'bin' => $r['Bin'],
                'n' => (int) $r['N_Seniors'],
                'predicted' => (float) $r['Mean_Predicted'],
                'actual' => (float) $r['Mean_Actual_Rule'],
                'abs_diff' => (float) $r['Abs_Diff'],
            ];
        }
        $meanAbsDiff = collect($calibration)->avg('abs_diff') ?: 0;

        $leak = $this->json('leakage_check_report.json') ?? [];

        $meanCvR2 = collect($cv)->avg('gbr_r2') ?: 0;
        $meanRho = collect($corr)->avg('rho') ?: 0;

        return [
            'domains' => $domains,
            'cv' => $cv,
            'holdout' => $holdout,
            'correlation' => $corr,
            'table' => $table,
            'calibration' => $calibration,
            'mean_abs_diff' => $meanAbsDiff,
            'leakage' => [
                'cv_strategy' => $leak['cv_strategy'] ?? '5-fold cross-validation',
                'note' => $leak['leakage_check'] ?? null,
                'features_used' => count($leak['features_used_in_ml'] ?? []),
                'targets' => $leak['targets'] ?? array_keys($domains),
                'pass' => isset($leak['leakage_check']),
            ],
            'mean_cv_r2' => $meanCvR2,
            'mean_rho' => $meanRho,
            'verdicts' => [
                'accuracy' => $this->verdict($meanCvR2 >= 0.95, 'Strong', 'Adequate'),
                'agreement' => $this->verdict($meanRho >= 0.95, 'Strong', 'Adequate'),
                'leakage' => $this->verdict(isset($leak['leakage_check']), 'Clean', 'Unverified'),
                'calibration' => $this->verdict($meanAbsDiff < 0.12, 'Well-calibrated', 'Drifts'),
            ],
        ];
    }

    public function riskClassification(): array
    {
        $summary = $this->json('risk_level_validation_summary.json') ?? [];
        $cm = $summary['confusion_matrix'] ?? [];

        // Collapse the legacy 4-level matrix into the live 3 levels
        // (CRITICAL → HIGH). confusion_matrix is keyed [Pred_X][True_Y].
        $map = ['LOW' => 'LOW', 'MODERATE' => 'MODERATE', 'HIGH' => 'HIGH', 'CRITICAL' => 'HIGH'];
        $matrix = [];
        foreach (self::LEVELS as $t) {
            foreach (self::LEVELS as $p) {
                $matrix[$t][$p] = 0;
            }
        }
        foreach ($cm as $predKey => $trueCounts) {
            $pred = $map[str_replace('Pred_', '', $predKey)] ?? null;
            if (! $pred) {
                continue;
            }
            foreach ($trueCounts as $trueKey => $count) {
                $true = $map[str_replace('True_', '', $trueKey)] ?? null;
                if (! $true) {
                    continue;
                }
                $matrix[$true][$pred] += (int) $count;
            }
        }

        // Derive 3-level metrics straight from the collapsed matrix so every
        // headline number is internally consistent with what's displayed.
        $total = 0;
        $diag = 0;
        $perClass = [];
        foreach (self::LEVELS as $lvl) {
            $tp = $matrix[$lvl][$lvl];
            $rowSum = array_sum($matrix[$lvl]);                       // actual = lvl
            $colSum = array_sum(array_column($matrix, $lvl));         // predicted = lvl
            $precision = $colSum ? $tp / $colSum : 0;
            $recall = $rowSum ? $tp / $rowSum : 0;
            $f1 = ($precision + $recall) ? 2 * $precision * $recall / ($precision + $recall) : 0;
            $perClass[$lvl] = [
                'precision' => $precision,
                'recall' => $recall,
                'f1' => $f1,
                'support' => $rowSum,
            ];
            $diag += $tp;
            $total += $rowSum;
        }
        $accuracy = $total ? $diag / $total : 0;
        $weightedF1 = $total
            ? array_sum(array_map(fn ($l) => $perClass[$l]['f1'] * $perClass[$l]['support'], self::LEVELS)) / $total
            : 0;

        // Distribution (collapse CRITICAL → HIGH).
        $dist = [];
        foreach ($this->csv('risk_level_distribution.csv') as $r) {
            $lvl = $map[$r['Risk_Level']] ?? $r['Risk_Level'];
            $dist[$lvl] = ($dist[$lvl] ?? 0) + (int) $r['Count'];
        }

        // Threshold trade-off table — explains why "moderate" was selected.
        $thresholds = [];
        foreach ($this->csv('threshold_comparison_results.csv') as $r) {
            $thresholds[] = [
                'set' => $r['Threshold_Set'],
                'high_recall' => (float) $r['HIGH_Recall'],
                'weighted_f1' => (float) $r['Weighted_F1'],
                'false_escalations' => (int) $r['Total_False_Escalations'],
            ];
        }

        return [
            'matrix' => $matrix,
            'levels' => self::LEVELS,
            'accuracy' => $accuracy,
            'weighted_f1' => $weightedF1,
            'high_recall' => $perClass['HIGH']['recall'] ?? 0,
            'per_class' => $perClass,
            'distribution' => $dist,
            'thresholds' => $thresholds,
            'selected_threshold' => $summary['selected_threshold_set'] ?? 'moderate',
            'verdicts' => [
                'accuracy' => $this->verdict($accuracy >= 0.70, 'Strong', 'Adequate'),
                'safety' => $this->verdict(($perClass['HIGH']['recall'] ?? 0) >= 0.85, 'High-sensitivity', 'Moderate'),
            ],
        ];
    }

    // ── Pillar 3 · Explainability (XAI) ─────────────────────────────────

    public function explainability(): array
    {
        // Kruskal-Wallis: which survey signals separate the care groups.
        $kruskal = [];
        foreach ($this->csv('feature_significance_kruskal.csv') as $r) {
            $kruskal[] = [
                'feature' => $r['Feature'],
                'label' => XaiFeatureLabels::label($r['Feature']),
                'h' => (float) $r['H-statistic'],
                'p' => (float) $r['p-value'],
                'significant' => trim($r['Significant (p<0.05)']) === 'True',
            ];
        }
        $sigCount = collect($kruskal)->where('significant', true)->count();
        $sorted = collect($kruskal)->sortByDesc('h')->values();
        $topFeatures = $sorted->take(12)->all();
        $allFeatures = $sorted->all();

        // Multicollinearity control (VIF) — confirms features aren't redundant.
        $vifRows = $this->csv('vif_final.csv');
        $vifMax = collect($vifRows)->max(fn ($r) => (float) $r['VIF']) ?: 0;

        // Rule sanity — does each domain actually separate low vs high risk?
        $sanity = [];
        foreach ($this->csv('rule_sanity_check.csv') as $r) {
            $sanity[] = [
                'domain' => $r['Domain'],
                'low_mean' => (float) $r['Low_Mean'],
                'high_mean' => (float) $r['High_Mean'],
                'pass' => trim($r['Pass_p005']) === 'True',
            ];
        }

        // Scoring weights — the transparency of the rule layer.
        $weights = $this->json('scoring_weights_documentation.json') ?? [];
        $vifThreshold = (float) ($weights['vif_threshold'] ?? 5.0);

        return [
            'kruskal_top' => $topFeatures,
            'kruskal_all' => $allFeatures,
            'kruskal_significant' => $sigCount,
            'kruskal_total' => count($kruskal),
            'sanity' => $sanity,
            'sanity_all_pass' => collect($sanity)->every(fn ($s) => $s['pass']),
            'section_weights' => $weights['section_weights'] ?? [],
            'domain_weights' => $weights['domain_risk_weights'] ?? [],
            'ensemble_weights' => $weights['ensemble_weights'] ?? [],
            'composite_weights' => $weights['composite_risk_domain_weights'] ?? [],
            'risk_thresholds' => $weights['risk_level_thresholds'] ?? [],
            'vif' => [
                'n_features' => count($vifRows),
                'max' => $vifMax,
                'threshold' => $vifThreshold,
                'all_below' => $vifMax > 0 && $vifMax < $vifThreshold,
            ],
            'verdicts' => [
                'significance' => $this->verdict($sigCount >= count($kruskal) - 2, 'Strong', 'Mixed'),
                'sanity' => $this->verdict(collect($sanity)->every(fn ($s) => $s['pass']), 'All domains separate', 'Some overlap'),
            ],
        ];
    }

    // ── Pillar 4 · Recommendations ──────────────────────────────────────

    public function recommendations(): array
    {
        $rows = $this->csv('recommendation_summary.csv');
        $total = count($rows);
        $withRecs = collect($rows)->filter(fn ($r) => (int) $r['n_all_recs'] > 0)->count();
        $avgAll = $total ? collect($rows)->avg(fn ($r) => (int) $r['n_all_recs']) : 0;
        $priority = collect($rows)->filter(fn ($r) => (int) $r['n_top_recs'] >= 5)->count();

        // Category frequency across the "top_categories" column.
        $catCounts = [];
        foreach ($rows as $r) {
            foreach (array_map('trim', explode(',', $r['top_categories'] ?? '')) as $cat) {
                if ($cat === '') {
                    continue;
                }
                $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
            }
        }
        arsort($catCounts);

        // Suggestion depth per risk level — higher risk earns a longer priority list.
        $byLevel = [];
        foreach (self::LEVELS as $lvl) {
            $bucket = collect($rows)->filter(fn ($r) => strtoupper($r['risk_level'] ?? '') === $lvl);
            $byLevel[$lvl] = [
                'count' => $bucket->count(),
                'avg_top' => $bucket->count() ? $bucket->avg(fn ($r) => (int) $r['n_top_recs']) : 0,
                'avg_all' => $bucket->count() ? $bucket->avg(fn ($r) => (int) $r['n_all_recs']) : 0,
            ];
        }

        // Per-recommendation breakdown (one row per suggestion) — the authoritative
        // counts by category, priority, citation, and human-validation requirement.
        $flat = $this->csv('senior_recommendations_flat.csv');
        $flatTotal = count($flat);
        $catFull = [];
        $priFull = [];
        $needsValidation = 0;
        $evidence = [];
        foreach ($flat as $row) {
            $cat = trim($row['category'] ?? '');
            if ($cat !== '') {
                $catFull[$cat] = ($catFull[$cat] ?? 0) + 1;
            }
            $pri = trim($row['priority'] ?? '');
            if ($pri !== '') {
                $priFull[$pri] = ($priFull[$pri] ?? 0) + 1;
            }
            if ((int) ($row['requires_human_validation'] ?? 0) === 1) {
                $needsValidation++;
            }
            $src = trim($row['evidence_source'] ?? '');
            if ($src !== '') {
                $evidence[$src] = ($evidence[$src] ?? 0) + 1;
            }
        }
        arsort($catFull);
        // Order priority buckets by urgency for display.
        $priOrder = ['Immediate', 'Urgent', 'Planned', 'Maintenance'];
        uksort($priFull, fn ($a, $b) => array_search($a, $priOrder) <=> array_search($b, $priOrder));
        arsort($evidence);

        return [
            'total' => $total,
            'with_recs' => $withRecs,
            'coverage' => $total ? $withRecs / $total : 0,
            'avg_all' => $avgAll,
            'priority_count' => $priority,
            'categories' => $catCounts,
            'by_level' => $byLevel,
            'flat_total' => $flatTotal,
            'cat_full' => $catFull,
            'pri_full' => $priFull,
            'needs_validation' => $needsValidation,
            'evidence_sources' => $evidence,
            'verdicts' => [
                'coverage' => $this->verdict($total > 0 && $withRecs === $total, 'Full coverage', 'Partial'),
            ],
        ];
    }

    // ── Provenance ──────────────────────────────────────────────────────

    public function provenance(): array
    {
        $fidelity = [];
        foreach ($this->csv('cache_vs_live_model_comparison.csv') as $r) {
            $fidelity[] = $r;
        }
        $recRows = $this->csv('recommendation_summary.csv');

        return [
            'n_seniors' => count($recRows),
            'fidelity' => $fidelity,
            'generated_at' => $this->generatedAt(),
        ];
    }

    // ── Charts payload (consumed by Chart.js island) ────────────────────

    public function chartPayload(array $clustering, array $regression, array $classification, array $xai, array $recs): array
    {
        return [
            'kSelection' => [
                'labels' => array_map(fn ($r) => 'K='.$r['k'], $clustering['sweep']),
                'silhouette' => array_map(fn ($r) => $r['silhouette'], $clustering['sweep']),
                'davies' => array_map(fn ($r) => $r['davies_bouldin'], $clustering['sweep']),
                'chosen' => $clustering['k_chosen'],
            ],
            'cvR2' => [
                'labels' => array_values($regression['domains']),
                'gbr' => array_map(fn ($k) => $regression['cv'][$k]['gbr_r2'] ?? null, array_keys($regression['domains'])),
                'rfr' => array_map(fn ($k) => $regression['cv'][$k]['rfr_r2'] ?? null, array_keys($regression['domains'])),
            ],
            'calibration' => [
                'labels' => array_map(fn ($r) => $r['bin'], $regression['calibration']),
                'predicted' => array_map(fn ($r) => round($r['predicted'], 3), $regression['calibration']),
                'actual' => array_map(fn ($r) => round($r['actual'], 3), $regression['calibration']),
            ],
            'confusion' => [
                'levels' => $classification['levels'],
                'matrix' => $classification['matrix'],
            ],
            'kruskal' => [
                'labels' => array_map(fn ($r) => $r['label'], $xai['kruskal_top']),
                'values' => array_map(fn ($r) => round($r['h'], 1), $xai['kruskal_top']),
            ],
            'categories' => [
                'labels' => array_keys($recs['cat_full'] ?: $recs['categories']),
                'values' => array_values($recs['cat_full'] ?: $recs['categories']),
            ],
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function verdict(bool $good, string $strong, string $weak): array
    {
        return ['good' => $good, 'label' => $good ? $strong : $weak];
    }

    /** Parse a Python-style dict string (e.g. tuned hyperparameters) into an array. */
    private function pyDict(string $s): array
    {
        $s = trim($s);
        if ($s === '') {
            return [];
        }
        $json = str_replace(["'", 'None', 'True', 'False'], ['"', 'null', 'true', 'false'], $s);
        $arr = json_decode($json, true);

        return is_array($arr) ? $arr : [];
    }
}
