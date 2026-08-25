<?php

namespace App\Livewire\Reports;

use App\Models\MlResult;
use App\Services\ClusterAnalyticsService;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class RiskReport extends Component
{
    use WithPagination;

    private ClusterAnalyticsService $clusterAnalytics;

    public string $filterRisk = '';

    public string $filterBarangay = '';

    public string $filterCluster = '';

    public string $filterSearch = '';

    public string $sortBy = 'composite_risk';

    public string $sortDir = 'desc';

    public function boot(ClusterAnalyticsService $clusterAnalytics): void
    {
        $this->clusterAnalytics = $clusterAnalytics;
    }

    public function placeholder(): View
    {
        return view('components.skeletons.report-panel');
    }

    public function render()
    {
        $latestIds = $this->clusterAnalytics->latestResultIds();

        $query = MlResult::with(['seniorCitizen'])
            ->whereIn('id', $latestIds)
            ->when($this->filterRisk, fn ($q) => $q->where('overall_risk_level', strtoupper($this->filterRisk)))
            ->when($this->filterCluster, fn ($q) => $q->where('cluster_named_id', $this->filterCluster))
            ->when($this->filterBarangay, fn ($q) => $q->whereHas('seniorCitizen', fn ($sq) => $sq->active()->where('barangay', $this->filterBarangay))
            )
            ->when($this->filterSearch, fn ($q) => $q->whereHas('seniorCitizen', fn ($sq) => $sq->searchTerm($this->filterSearch)))
            ->orderBy($this->sortBy, $this->sortDir);

        $records = $query->paginate(25);

        $summaryStats = MlResult::whereIn('id', $latestIds)
            ->selectRaw('
                overall_risk_level,
                COUNT(*) as count,
                ROUND(AVG(composite_risk), 4) as avg_risk,
                ROUND(AVG(wellbeing_score), 4) as avg_wellbeing
            ')
            ->groupBy('overall_risk_level')
            ->get()
            ->keyBy('overall_risk_level');

        return view('livewire.reports.risk-report', compact('records', 'summaryStats'));
    }

    public function sortColumn(string $col): void
    {
        $allowed = ['composite_risk', 'overall_risk_level', 'ic_risk', 'env_risk', 'func_risk', 'wellbeing_score'];
        if (! in_array($col, $allowed, true)) {
            return;
        }

        if ($this->sortBy === $col) {
            $this->sortDir = $this->sortDir === 'desc' ? 'asc' : 'desc';
        } else {
            $this->sortBy = $col;
            $this->sortDir = 'desc';
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['filterRisk', 'filterBarangay', 'filterCluster', 'filterSearch']);
        $this->resetPage();
    }

    public function updatedFilterRisk(): void
    {
        $this->resetPage();
    }

    public function updatedFilterBarangay(): void
    {
        $this->resetPage();
    }

    public function updatedFilterCluster(): void
    {
        $this->resetPage();
    }

    public function updatedFilterSearch(): void
    {
        $this->resetPage();
    }
}
