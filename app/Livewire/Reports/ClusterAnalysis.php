<?php

namespace App\Livewire\Reports;

use App\Services\ClusterAnalyticsService;
use Livewire\Component;
use Livewire\WithPagination;

class ClusterAnalysis extends Component
{
    use WithPagination;

    private ClusterAnalyticsService $clusterAnalytics;

    public string $selectedBarangay = '';
    public string $sortBy = 'composite_risk';
    public string $sortDir = 'desc';

    public function boot(ClusterAnalyticsService $clusterAnalytics): void
    {
        $this->clusterAnalytics = $clusterAnalytics;
    }

    public function render()
    {
        $baseQuery = $this->clusterAnalytics
            ->latestResultsQuery($this->selectedBarangay ?: null);

        // Full collection for aggregate computations (charts, summaries)
        $results = (clone $baseQuery)->get();

        // Paginated records for the member table
        $records = $baseQuery
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25);

        // Cluster summaries
        $clusterSummaries = $results->groupBy('cluster_named_id')->map(function ($group, $clusterId) {
            return [
                'id'                 => $clusterId,
                'name'               => $group->first()->cluster_name,
                'count'              => $group->count(),
                'avg_composite'      => round($group->avg('composite_risk'), 3),
                'avg_ic'             => round($group->avg('ic_risk'), 3),
                'avg_env'            => round($group->avg('env_risk'), 3),
                'avg_func'           => round($group->avg('func_risk'), 3),
                'high_count'         => $group->where('overall_risk_level', 'HIGH')->count(),
                'barangay_top'       => $group->map(fn($r) => $r->seniorCitizen?->barangay)
                                              ->filter()->countBy()->sortDesc()->keys()->first(),
            ];
        })->sortKeys();

        // WHO domain comparison across clusters
        $domainChart = [
            'labels'   => ['Intrinsic Capacity', 'Environment', 'Functional'],
            'datasets' => $results->groupBy('cluster_named_id')->map(function ($group, $id) {
                $colors = [1 => '#10b981', 2 => '#f59e0b', 3 => '#f43f5e'];
                return [
                    'label'           => "Cluster {$id}: " . $group->first()->cluster_name,
                    'data'            => [
                        round($group->avg('ic_risk') * 100, 1),
                        round($group->avg('env_risk') * 100, 1),
                        round($group->avg('func_risk') * 100, 1),
                    ],
                    'backgroundColor' => $colors[$id] ?? '#94a3b8',
                    'borderRadius'    => 4,
                ];
            })->values()->toArray(),
        ];

        // Risk distribution per cluster for stacked chart
        $riskByCluster = $results->groupBy('cluster_named_id')->map(function ($group) {
            return [
                'LOW'      => $group->where('overall_risk_level', 'LOW')->count(),
                'MODERATE' => $group->where('overall_risk_level', 'MODERATE')->count(),
                'HIGH'     => $group->where('overall_risk_level', 'HIGH')->count(),
            ];
        });

        $evalMetrics = \App\Support\ClusterMetrics::load();

        return view('livewire.reports.cluster-analysis', compact(
            'results', 'records', 'clusterSummaries', 'domainChart', 'riskByCluster', 'evalMetrics'
        ));
    }

    public function sortColumn(string $col): void
    {
        if ($this->sortBy === $col) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy  = $col;
            $this->sortDir = 'desc';
        }
        $this->resetPage();
    }

    public function updatedSelectedBarangay(): void
    {
        $this->resetPage();
    }
}
