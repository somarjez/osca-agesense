<?php

namespace App\Support;

class RiskThresholds
{
    /**
     * Load risk-band boundaries from python/models/risk_thresholds.json — the
     * single source of truth shared with the Python inference service (see
     * python/services/inference_service.py's _load_risk_thresholds()), which
     * reads the same file. Prevents the fallback-scoring path (MlService's
     * fallbackInfer()/computePriorityFlag(), used when the ML services are
     * down) from silently classifying the same composite_risk into a
     * different band than the live model would (TC-ML-06).
     *
     * Same path-resolution pattern as ClusterMetrics::load(). Falls back to
     * these same tuned values (kept identical to the JSON's own defaults on
     * purpose) if the file is missing/unreadable, so an environment without
     * it still classifies correctly instead of reverting to stale numbers.
     */
    public static function load(): array
    {
        $defaults = [
            'critical' => 0.70,
            'high' => 0.54,
            'moderate' => 0.39,
        ];

        $configured = env('ML_MODELS_PATH', 'python/models');
        $modelsDir = str_starts_with($configured, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $configured)
            ? $configured
            : base_path($configured);
        $path = $modelsDir.DIRECTORY_SEPARATOR.'risk_thresholds.json';

        if (! file_exists($path)) {
            return $defaults;
        }

        $data = json_decode(file_get_contents($path), true);
        if (! is_array($data)) {
            return $defaults;
        }

        $numeric = array_filter(
            array_intersect_key($data, $defaults),
            fn ($v) => is_numeric($v)
        );

        return array_merge($defaults, array_map('floatval', $numeric));
    }
}
