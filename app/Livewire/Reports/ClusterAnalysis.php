<?php

namespace App\Livewire\Reports;

use App\Models\SeniorCitizen;
use App\Services\ClusterAnalyticsService;
use App\Support\ClusterMetrics;
use Illuminate\Support\Facades\DB;
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

        // Paginated records for the member table
        $records = (clone $baseQuery)
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25);

        $resultsCount = (clone $baseQuery)->count();

        // Per-cluster aggregate (count, averages, risk-level pivot) computed in SQL
        // instead of pulling the full unfiltered result set into PHP.
        $clusterAgg = (clone $baseQuery)
            ->without('seniorCitizen')
            ->select(
                'cluster_named_id',
                DB::raw('MIN(cluster_name) as cluster_name'),
                DB::raw('COUNT(*) as count'),
                DB::raw('AVG(composite_risk) as avg_composite'),
                DB::raw('AVG(ic_risk) as avg_ic'),
                DB::raw('AVG(env_risk) as avg_env'),
                DB::raw('AVG(func_risk) as avg_func'),
                DB::raw("SUM(CASE WHEN overall_risk_level = 'HIGH' THEN 1 ELSE 0 END) as high_count"),
                DB::raw("SUM(CASE WHEN overall_risk_level = 'MODERATE' THEN 1 ELSE 0 END) as moderate_count"),
                DB::raw("SUM(CASE WHEN overall_risk_level = 'LOW' THEN 1 ELSE 0 END) as low_count")
            )
            ->groupBy('cluster_named_id')
            ->orderBy('cluster_named_id')
            ->get()
            ->keyBy('cluster_named_id');

        // Top barangay per cluster — a small (barangay x cluster) result set, cheap
        // to reduce to a top-1 pick in PHP.
        $barangayTop = SeniorCitizen::active()
            ->join('ml_results', function ($join) {
                $join->on('senior_citizens.id', '=', 'ml_results.senior_citizen_id')
                    ->whereIn('ml_results.id', $this->clusterAnalytics->latestResultIds());
            })
            ->whereNotNull('ml_results.cluster_named_id')
            ->when(
                $this->selectedBarangay,
                fn ($q) => $q->where('senior_citizens.barangay', $this->selectedBarangay)
            )
            ->select('senior_citizens.barangay', 'ml_results.cluster_named_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('senior_citizens.barangay', 'ml_results.cluster_named_id')
            ->get()
            ->groupBy('cluster_named_id')
            ->map(fn ($rows) => $rows->sortByDesc('cnt')->first()->barangay ?? null);

        $clusterSummaries = $clusterAgg->map(fn ($row, $clusterId) => [
            'id' => $clusterId,
            'name' => $row->cluster_name,
            'count' => (int) $row->count,
            'avg_composite' => round((float) $row->avg_composite, 3),
            'avg_ic' => round((float) $row->avg_ic, 3),
            'avg_env' => round((float) $row->avg_env, 3),
            'avg_func' => round((float) $row->avg_func, 3),
            'high_count' => (int) $row->high_count,
            'barangay_top' => $barangayTop[$clusterId] ?? null,
        ])->sortKeys();

        // WHO domain comparison across clusters
        $domainChart = [
            'labels' => ['Intrinsic Capacity', 'Environment', 'Functional'],
            'datasets' => $clusterAgg->map(function ($row, $id) {
                $colors = [1 => '#2ecc71', 2 => '#3498db', 3 => '#f39c12', 4 => '#e74c3c'];

                return [
                    'label' => "Cluster {$id}: ".$row->cluster_name,
                    'data' => [
                        round((float) $row->avg_ic * 100, 1),
                        round((float) $row->avg_env * 100, 1),
                        round((float) $row->avg_func * 100, 1),
                    ],
                    'backgroundColor' => $colors[$id] ?? '#94a3b8',
                    'borderRadius' => 4,
                ];
            })->values()->toArray(),
        ];

        // Risk distribution per cluster for stacked chart
        $riskByCluster = $clusterAgg->map(fn ($row) => [
            'LOW' => (int) $row->low_count,
            'MODERATE' => (int) $row->moderate_count,
            'HIGH' => (int) $row->high_count,
        ]);

        $evalMetrics = ClusterMetrics::load();

        return view('livewire.reports.cluster-analysis', compact(
            'resultsCount', 'records', 'clusterSummaries', 'domainChart', 'riskByCluster', 'evalMetrics'
        ));
    }

    public function sortColumn(string $col): void
    {
        if ($this->sortBy === $col) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $col;
            $this->sortDir = 'desc';
        }
        $this->resetPage();
    }

    public function updatedSelectedBarangay(): void
    {
        $this->resetPage();
    }
}
