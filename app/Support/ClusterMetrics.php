<?php

namespace App\Support;

class ClusterMetrics
{
    /**
     * Load cluster evaluation metrics from python/models/cluster_eval_metrics.json.
     * Falls back to null values if the file is missing (metrics not yet generated).
     */
    public static function load(): array
    {
        $configured = env('ML_MODELS_PATH', 'python/models');
        // Resolve relative paths against the project root so that a plain value
        // like "python/models" works the same as an absolute path.
        $modelsDir = str_starts_with($configured, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $configured)
            ? $configured
            : base_path($configured);
        $path = $modelsDir.DIRECTORY_SEPARATOR.'cluster_eval_metrics.json';

        $defaults = [
            'silhouette' => null,
            'davies_bouldin' => null,
            'calinski_harabasz' => null,
            'inertia' => null,
            'k_chosen' => 4,
        ];

        if (! file_exists($path)) {
            return $defaults;
        }

        $data = json_decode(file_get_contents($path), true);

        return array_merge($defaults, array_intersect_key($data ?? [], $defaults));
    }
}
