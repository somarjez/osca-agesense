<?php

namespace App\Livewire\Dashboard;

use App\Models\MlResult;
use App\Models\QolSurvey;
use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use App\Services\ClusterAnalyticsService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class MainDashboard extends Component
{
    private ClusterAnalyticsService $clusterAnalytics;
    private ?\Illuminate\Support\Collection $_latestIds = null;

    public string $selectedBarangay = '';
    public string $selectedRisk = '';
    public string $dateRange = '30';  // days

    public function boot(ClusterAnalyticsService $clusterAnalytics): void
    {
        $this->clusterAnalytics = $clusterAnalytics;
    }

    public function render()
    {
        $this->_latestIds = null;

        return view('livewire.dashboard.main-dashboard', [
            'stats'               => $this->getStats(),
            'riskDistribution'    => $this->getRiskDistribution(),
            'clusterDistribution' => $this->getClusterDistribution(),
            'barangayBreakdown'   => $this->getBarangayBreakdown(),
            'recentSeniors'       => $this->getRecentSeniors(),
            'pendingRecs'         => $this->getPendingRecommendations(),
            'domainScoreChart'    => $this->getDomainScores(),
            'ageGroupChart'       => $this->getAgeGroupDistribution(),
            'mlHealth'            => $this->getMlServiceHealth(),
        ]);
    }

    private function latestMlIds(): \Illuminate\Support\Collection
    {
        return $this->_latestIds ??= MlResult::select(DB::raw('MAX(id) as id'))->groupBy('senior_citizen_id')->pluck('id');
    }

    private function getStats(): array
    {
        $latestIds = $this->latestMlIds();

        $baseQuery = SeniorCitizen::active()
            ->when($this->selectedBarangay, fn($q) => $q->where('barangay', $this->selectedBarangay))
            ->when($this->selectedRisk, fn($q) => $q->byRiskLevel($this->selectedRisk));

        $total = $baseQuery->count();

        $surveyed = QolSurvey::where('status', 'processed')
            ->when($this->selectedBarangay, fn($q) => $q->whereHas('seniorCitizen',
                fn($sq) => $sq->where('barangay', $this->selectedBarangay)
            ))
            ->distinct('senior_citizen_id')
            ->count();

        $highRisk = MlResult::whereIn('id', $latestIds)
            ->where('overall_risk_level', 'HIGH')
            ->when($this->selectedBarangay, fn($q) => $q->whereHas('seniorCitizen',
                fn($sq) => $sq->where('barangay', $this->selectedBarangay)
            ))
            ->count();

        $urgent = MlResult::whereIn('id', $latestIds)
            ->where('priority_flag', 'urgent')
            ->when($this->selectedBarangay, fn($q) => $q->whereHas('seniorCitizen',
                fn($sq) => $sq->where('barangay', $this->selectedBarangay)
            ))
            ->count();

        $pendingRecs = Recommendation::where('status', 'pending')
            ->when($this->selectedBarangay, fn($q) => $q->whereHas('seniorCitizen',
                fn($sq) => $sq->where('barangay', $this->selectedBarangay)
            ))
            ->count();

        return compact('total', 'surveyed', 'urgent', 'highRisk', 'pendingRecs');
    }

    private function getRiskDistribution(): array
    {
        $latestIds = $this->latestMlIds();

        $latest = MlResult::select('overall_risk_level', DB::raw('COUNT(*) as count'))
            ->whereIn('id', $latestIds)
            ->when($this->selectedBarangay, fn($q) => $q->whereHas('seniorCitizen',
                fn($sq) => $sq->where('barangay', $this->selectedBarangay)
            ))
            ->when($this->selectedRisk, fn($q) => $q->where('overall_risk_level', strtoupper($this->selectedRisk)))
            ->groupBy('overall_risk_level')
            ->pluck('count', 'overall_risk_level')
            ->toArray();

        return [
            'labels' => ['HIGH','MODERATE','LOW'],
            'data'   => [
                $latest['HIGH']     ?? 0,
                $latest['MODERATE'] ?? 0,
                $latest['LOW']      ?? 0,
            ],
            'colors' => ['#ea580c','#ca8a04','#16a34a'],
        ];
    }

    private function getClusterDistribution(): array
    {
        return $this->clusterAnalytics->clusterDistribution($this->selectedBarangay ?: null);
    }

    private function getBarangayBreakdown(): array
    {
        $latestIds = $this->latestMlIds();

        $totals = SeniorCitizen::active()
            ->select('barangay', DB::raw('COUNT(*) as total'))
            ->when($this->selectedBarangay, fn($q) => $q->where('barangay', $this->selectedBarangay))
            ->when($this->selectedRisk, fn($q) => $q->byRiskLevel($this->selectedRisk))
            ->groupBy('barangay')
            ->orderByDesc('total')
            ->pluck('total', 'barangay');

        $mlCounts = DB::table('ml_results')
            ->join('senior_citizens', 'senior_citizens.id', '=', 'ml_results.senior_citizen_id')
            ->whereIn('ml_results.id', $latestIds)
            ->where('senior_citizens.status', 'active')
            ->when($this->selectedBarangay, fn($q) => $q->where('senior_citizens.barangay', $this->selectedBarangay))
            ->select(
                'senior_citizens.barangay',
                DB::raw("SUM(CASE WHEN ml_results.overall_risk_level = 'HIGH' THEN 1 ELSE 0 END) as high_count"),
                DB::raw("SUM(CASE WHEN ml_results.priority_flag = 'urgent' THEN 1 ELSE 0 END) as urgent_count")
            )
            ->groupBy('senior_citizens.barangay')
            ->get()
            ->keyBy('barangay');

        return $totals->map(fn($total, $barangay) => [
            'barangay' => $barangay,
            'total'    => $total,
            'high'     => (int) ($mlCounts[$barangay]->high_count ?? 0),
            'urgent'   => (int) ($mlCounts[$barangay]->urgent_count ?? 0),
        ])->values()->toArray();
    }

    private function getRecentSeniors(): \Illuminate\Database\Eloquent\Collection
    {
        return SeniorCitizen::active()
            ->with(['latestMlResult'])
            ->when($this->selectedBarangay, fn($q) => $q->where('barangay', $this->selectedBarangay))
            ->when($this->selectedRisk, fn($q) => $q->byRiskLevel($this->selectedRisk))
            ->latest()
            ->limit(10)
            ->get();
    }

    private function getPendingRecommendations(): \Illuminate\Database\Eloquent\Collection
    {
        return Recommendation::with(['seniorCitizen'])
            ->pending()
            ->whereHas('seniorCitizen')
            ->where('urgency', 'urgent')
            ->orderBy('priority')
            ->limit(8)
            ->get();
    }

    private function getDomainScores(): array
    {
        $avgs = QolSurvey::where('status', 'processed')
            ->when($this->selectedBarangay, fn($q) => $q->whereHas('seniorCitizen',
                fn($sq) => $sq->where('barangay', $this->selectedBarangay)
            ))
            ->when($this->selectedRisk, fn($q) => $q->whereHas('seniorCitizen',
                fn($sq) => $sq->byRiskLevel($this->selectedRisk)
            ))
            ->selectRaw('
                AVG(score_qol) as qol,
                AVG(score_physical) as physical,
                AVG(score_psychological) as psychological,
                AVG(score_independence) as independence,
                AVG(score_social) as social,
                AVG(score_environment) as environment,
                AVG(score_financial) as financial,
                AVG(score_spirituality) as spirituality
            ')
            ->first();

        return [
            'labels' => ['QoL','Physical','Psychological','Independence','Social','Environment','Financial','Spirituality'],
            'data'   => collect(['qol','physical','psychological','independence','social','environment','financial','spirituality'])
                ->map(fn($k) => round(($avgs?->$k ?? 0) * 100, 1))
                ->toArray(),
        ];
    }

    private function getAgeGroupDistribution(): array
    {
        $driver = DB::connection()->getDriverName();
        $ageExpr = $driver === 'sqlite'
            ? "(CAST(strftime('%Y','now') AS INTEGER) - CAST(strftime('%Y', date_of_birth) AS INTEGER) - (strftime('%m-%d','now') < strftime('%m-%d', date_of_birth)))"
            : 'TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE())';

        $groups = SeniorCitizen::active()
            ->when($this->selectedBarangay, fn($q) => $q->where('barangay', $this->selectedBarangay))
            ->when($this->selectedRisk, fn($q) => $q->byRiskLevel($this->selectedRisk))
            ->selectRaw("
                CASE
                    WHEN {$ageExpr} BETWEEN 60 AND 64 THEN '60–64'
                    WHEN {$ageExpr} BETWEEN 65 AND 69 THEN '65–69'
                    WHEN {$ageExpr} BETWEEN 70 AND 74 THEN '70–74'
                    WHEN {$ageExpr} BETWEEN 75 AND 79 THEN '75–79'
                    WHEN {$ageExpr} BETWEEN 80 AND 84 THEN '80–84'
                    ELSE '85+' END AS age_group,
                COUNT(*) as count
            ")
            ->groupBy('age_group')
            ->orderBy('age_group')
            ->pluck('count', 'age_group');

        return [
            'labels' => ['60–64','65–69','70–74','75–79','80–84','85+'],
            'data'   => collect(['60–64','65–69','70–74','75–79','80–84','85+'])
                ->map(fn($g) => $groups[$g] ?? 0)
                ->toArray(),
        ];
    }

    private function getMlServiceHealth(): array
    {
        try {
            return app(\App\Services\MlService::class)->healthCheck();
        } catch (\Exception $e) {
            return ['preprocessor' => 'unreachable', 'inference' => 'unreachable'];
        }
    }

    public function clearFilters(): void
    {
        $this->selectedBarangay = '';
        $this->selectedRisk     = '';
    }

    public function updatedSelectedBarangay(): void {}
    public function updatedSelectedRisk(): void {}
}
