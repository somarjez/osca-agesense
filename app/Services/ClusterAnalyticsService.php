<?php

namespace App\Services;

use App\Models\MlResult;
use App\Models\SeniorCitizen;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ClusterAnalyticsService
{
    public function latestResultIds(): Collection
    {
        return MlResult::select(DB::raw('MAX(id) as id'))
            ->groupBy('senior_citizen_id')
            ->pluck('id');
    }

    public function latestResultsQuery(?string $barangay = null): Builder
    {
        return MlResult::with(['seniorCitizen'])
            ->whereIn('id', $this->latestResultIds())
            ->whereNotNull('cluster_named_id')
            ->when($barangay, fn ($query) => $query->whereHas(
                'seniorCitizen',
                fn ($seniorQuery) => $seniorQuery->where('barangay', $barangay)
            ));
    }

    public function clusterDistribution(?string $barangay = null): array
    {
        $groups = $this->latestResultsQuery($barangay)
            ->get()
            ->groupBy('cluster_named_id')
            ->sortKeys();

        return [
            'ids' => $groups->keys()->map(fn ($id) => (int) $id)->values()->toArray(),
            'labels' => $groups->map(fn ($group) => $group->first()->cluster_name)->values()->toArray(),
            'data' => $groups->map(fn ($group) => $group->count())->values()->toArray(),
            'colors' => $groups->keys()->map(fn ($id) => $this->clusterColor((int) $id))->values()->toArray(),
        ];
    }

    public function clusterSummary(): Collection
    {
        return MlResult::whereIn('id', $this->latestResultIds())
            ->whereNotNull('cluster_named_id')
            ->select(
                'cluster_named_id',
                'cluster_name',
                DB::raw('COUNT(*) as member_count'),
                DB::raw('AVG(composite_risk) as avg_composite_risk'),
                DB::raw('AVG(ic_risk) as avg_ic_risk'),
                DB::raw('AVG(env_risk) as avg_env_risk'),
                DB::raw('AVG(func_risk) as avg_func_risk'),
                DB::raw('AVG(wellbeing_score) as avg_wellbeing')
            )
            ->groupBy('cluster_named_id', 'cluster_name')
            ->orderBy('cluster_named_id')
            ->get();
    }

    public function barangayClusterBreakdown(): Collection
    {
        return SeniorCitizen::active()
            ->join('ml_results', function ($join) {
                $join->on('senior_citizens.id', '=', 'ml_results.senior_citizen_id')
                    ->whereIn('ml_results.id', $this->latestResultIds());
            })
            ->select('barangay', 'cluster_named_id', 'cluster_name', DB::raw('COUNT(*) as count'))
            ->groupBy('barangay', 'cluster_named_id', 'cluster_name')
            ->orderBy('barangay')
            ->get()
            ->groupBy('barangay');
    }

    public function domainByCluster(): Collection
    {
        return MlResult::whereIn('id', $this->latestResultIds())
            ->whereNotNull('cluster_named_id')
            ->select(
                'cluster_named_id',
                DB::raw('AVG(ic_risk) as ic'),
                DB::raw('AVG(env_risk) as env'),
                DB::raw('AVG(func_risk) as func')
            )
            ->groupBy('cluster_named_id')
            ->orderBy('cluster_named_id')
            ->get()
            ->keyBy('cluster_named_id');
    }

    public function qolByCluster(): Collection
    {
        return DB::table('qol_surveys')
            ->join('ml_results', 'qol_surveys.id', '=', 'ml_results.qol_survey_id')
            ->whereIn('ml_results.id', $this->latestResultIds())
            ->whereNotNull('ml_results.cluster_named_id')
            ->where('qol_surveys.status', 'processed')
            ->select(
                'ml_results.cluster_named_id',
                DB::raw('AVG(qol_surveys.score_physical) as physical'),
                DB::raw('AVG(qol_surveys.score_psychological) as psychological'),
                DB::raw('AVG(qol_surveys.score_social) as social'),
                DB::raw('AVG(qol_surveys.score_financial) as financial'),
                DB::raw('AVG(qol_surveys.score_environment) as environment'),
                DB::raw('AVG(qol_surveys.overall_score) as overall')
            )
            ->groupBy('ml_results.cluster_named_id')
            ->orderBy('ml_results.cluster_named_id')
            ->get()
            ->keyBy('cluster_named_id');
    }

    private function clusterColor(int $clusterId): string
    {
        return match ($clusterId) {
            1 => '#10b981',
            2 => '#f59e0b',
            3 => '#f43f5e',
            default => '#94a3b8',
        };
    }
}
