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
        $path = env('ML_MODELS_PATH', base_path('python/models'))
              . DIRECTORY_SEPARATOR . 'cluster_eval_metrics.json';

        $defaults = [
            'silhouette'        => null,
            'davies_bouldin'    => null,
            'calinski_harabasz' => null,
            'inertia'           => null,
            'k_chosen'          => 3,
        ];

        if (!file_exists($path)) {
            return $defaults;
        }

        $data = json_decode(file_get_contents($path), true);

        return array_merge($defaults, array_intersect_key($data ?? [], $defaults));
    }
}
