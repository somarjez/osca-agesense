<?php

namespace App\Console\Commands;

use App\Models\ClusterSnapshot;
use App\Models\MlResult;
use App\Models\SeniorCitizen;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SnapshotClusters extends Command
{
    protected $signature   = 'osca:snapshot-clusters {--date= : Snapshot date (Y-m-d), defaults to today} {--force : Overwrite existing snapshot for this date}';
    protected $description = 'Record a daily cluster composition snapshot for longitudinal tracking.';

    public function handle(): int
    {
        $date = $this->option('date') ?? now()->toDateString();

        $exists = ClusterSnapshot::whereDate('snapshot_date', $date)->exists();

        if ($exists && !$this->option('force')) {
            $this->warn("A snapshot for {$date} already exists. Use --force to overwrite.");
            return Command::SUCCESS;
        }

        if ($exists) {
            ClusterSnapshot::whereDate('snapshot_date', $date)->delete();
            $this->line("Deleted existing snapshot for {$date}.");
        }

        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        $clusterSummary = MlResult::whereIn('id', $latestIds)
            ->whereNotNull('cluster_named_id')
            ->select(
                'cluster_named_id as cluster_id',
                'cluster_name',
                DB::raw('COUNT(*) as member_count'),
                DB::raw('AVG(composite_risk) as avg_composite_risk'),
                DB::raw('AVG(ic_risk) as avg_ic_risk'),
                DB::raw('AVG(env_risk) as avg_env_risk'),
                DB::raw('AVG(func_risk) as avg_func_risk')
            )
            ->groupBy('cluster_named_id', 'cluster_name')
            ->orderBy('cluster_named_id')
            ->get();

        if ($clusterSummary->isEmpty()) {
            $this->warn('No ML results found — snapshot not created.');
            return Command::FAILURE;
        }

        // Barangay distribution per cluster
        $barangayRows = SeniorCitizen::active()
            ->join('ml_results', function ($join) use ($latestIds) {
                $join->on('senior_citizens.id', '=', 'ml_results.senior_citizen_id')
                     ->whereIn('ml_results.id', $latestIds);
            })
            ->whereNotNull('ml_results.cluster_named_id')
            ->select('ml_results.cluster_named_id', 'senior_citizens.barangay', DB::raw('COUNT(*) as count'))
            ->groupBy('ml_results.cluster_named_id', 'senior_citizens.barangay')
            ->get()
            ->groupBy('cluster_named_id')
            ->map(fn($rows) => $rows->pluck('count', 'barangay')->toArray());

        // Risk distribution per cluster
        $riskRows = MlResult::whereIn('id', $latestIds)
            ->whereNotNull('cluster_named_id')
            ->select('cluster_named_id', 'overall_risk_level', DB::raw('COUNT(*) as count'))
            ->groupBy('cluster_named_id', 'overall_risk_level')
            ->get()
            ->groupBy('cluster_named_id')
            ->map(fn($rows) => $rows->pluck('count', 'overall_risk_level')->toArray());

        $inserted = 0;

        foreach ($clusterSummary as $cluster) {
            ClusterSnapshot::create([
                'snapshot_date'           => $date,
                'cluster_id'              => $cluster->cluster_id,
                'cluster_name'            => $cluster->cluster_name,
                'member_count'            => $cluster->member_count,
                'avg_composite_risk'      => round($cluster->avg_composite_risk, 4),
                'avg_ic_risk'             => round($cluster->avg_ic_risk, 4),
                'avg_env_risk'            => round($cluster->avg_env_risk, 4),
                'avg_func_risk'           => round($cluster->avg_func_risk, 4),
                'barangay_distribution'   => $barangayRows[$cluster->cluster_id] ?? [],
                'risk_level_distribution' => $riskRows[$cluster->cluster_id] ?? [],
            ]);
            $inserted++;
        }

        $this->info("Snapshot created for {$date}: {$inserted} cluster(s) recorded.");

        return Command::SUCCESS;
    }
}
