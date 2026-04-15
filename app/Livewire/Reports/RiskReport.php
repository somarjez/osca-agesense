<?php

namespace App\Livewire\Reports;

use App\Models\MlResult;
use App\Models\SeniorCitizen;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class RiskReport extends Component
{
    use WithPagination;

    public string $filterRisk     = '';
    public string $filterBarangay = '';
    public string $filterCluster  = '';
    public string $sortBy         = 'composite_risk';
    public string $sortDir        = 'desc';

    public function render()
    {
        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        $query = MlResult::with(['seniorCitizen'])
            ->whereIn('id', $latestIds)
            ->when($this->filterRisk,     fn($q) => $q->where('overall_risk_level', strtoupper($this->filterRisk)))
            ->when($this->filterCluster,  fn($q) => $q->where('cluster_named_id', $this->filterCluster))
            ->when($this->filterBarangay, fn($q) =>
                $q->whereHas('seniorCitizen', fn($sq) => $sq->where('barangay', $this->filterBarangay))
            )
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
        $this->sortBy  = ($this->sortBy === $col) ? $this->sortBy  : $col;
        $this->sortDir = ($this->sortBy === $col && $this->sortDir === 'desc') ? 'asc' : 'desc';
        if ($this->sortBy !== $col) { $this->sortBy = $col; $this->sortDir = 'desc'; }
        $this->resetPage();
    }

    public function updatedFilterRisk():     void { $this->resetPage(); }
    public function updatedFilterBarangay(): void { $this->resetPage(); }
    public function updatedFilterCluster():  void { $this->resetPage(); }
}
