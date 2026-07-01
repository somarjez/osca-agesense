<?php

namespace App\Http\Controllers;

use App\Support\ModelValidation;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ModelValidationController extends Controller
{
    /** Plots served from storage — whitelist guards the {name} route param. */
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

    public function index(ModelValidation $mv)
    {
        $clustering = $mv->clustering();
        $regression = $mv->riskRegression();
        $classification = $mv->riskClassification();
        $xai = $mv->explainability();
        $recs = $mv->recommendations();
        $fidelity = $mv->fidelity();
        $provenance = $mv->provenance();
        $confidence = $mv->confidence($clustering, $regression, $classification, $xai, $recs);

        $charts = $mv->chartPayload($clustering, $regression, $classification, $xai, $recs);

        return view('reports.validation', compact(
            'clustering', 'regression', 'classification', 'xai', 'recs', 'fidelity', 'provenance', 'charts', 'confidence'
        ) + ['available' => $mv->available()]);
    }

    /** Stream a whitelisted evaluation plot from storage (never from public/). */
    public function plot(string $name): BinaryFileResponse|Response
    {
        if (! in_array($name, self::PLOTS, true)) {
            abort(404);
        }
        $path = storage_path('app/ml_validation/plots/'.$name.'.png');
        if (! is_file($path)) {
            $path = base_path('../osca_output/plots/'.$name.'.png');
        }
        if (! is_file($path)) {
            abort(404);
        }

        return response()->file($path, ['Content-Type' => 'image/png']);
    }
}
