<?php

namespace App\Http\Controllers;

use App\Models\ClusterSnapshot;
use App\Models\MlResult;
use App\Models\QolSurvey;
use App\Models\Recommendation;
use App\Models\Facility;
use App\Models\SeniorCitizen;
use App\Support\DbHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * GIS Analytics landing page.
     */
    public function gis()
    {
        $mappedCount = SeniorCitizen::active()->count();
        $highRiskMapped = SeniorCitizen::active()
            ->whereHas('latestMlResult', fn($q) => $q->where('overall_risk_level', 'HIGH'))
            ->count();

        $stats = [
            'mapped_seniors' => $mappedCount,
            'high_risk_mapped' => $highRiskMapped,
            'barangays_covered' => SeniorCitizen::active()->distinct('barangay')->count('barangay'),
            'facilities_recorded' => Facility::query()->count(),
        ];

        return view('reports.gis', compact('stats'));
    }

    /**
     * Cluster Analysis report page.
     */
    public function cluster(Request $request)
    {
        // Latest ML result per senior
        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        $clusterSummary = MlResult::whereIn('id', $latestIds)
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

        // Barangay × Cluster breakdown
        $barangayCluster = SeniorCitizen::active()
            ->join('ml_results', function ($join) use ($latestIds) {
                $join->on('senior_citizens.id', '=', 'ml_results.senior_citizen_id')
                     ->whereIn('ml_results.id', $latestIds);
            })
            ->select('barangay', 'cluster_named_id', 'cluster_name', DB::raw('COUNT(*) as count'))
            ->groupBy('barangay', 'cluster_named_id', 'cluster_name')
            ->orderBy('barangay')
            ->get()
            ->groupBy('barangay');

        // WHO domain averages per cluster
        $domainByCluster = MlResult::whereIn('id', $latestIds)
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

        // QoL domain scores per cluster
        $qolByCluster = DB::table('qol_surveys')
            ->join('ml_results', 'qol_surveys.id', '=', 'ml_results.qol_survey_id')
            ->whereIn('ml_results.id', $latestIds)
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

        $evalMetrics = \App\Support\ClusterMetrics::load();

        // Snapshot history — last 30 snapshots grouped by date, ordered newest first
        $snapshots = ClusterSnapshot::orderByDesc('snapshot_date')
            ->orderBy('cluster_id')
            ->get()
            ->groupBy(fn($s) => $s->snapshot_date->format('Y-m-d'))
            ->take(30);

        return view('reports.cluster', compact(
            'clusterSummary', 'barangayCluster', 'domainByCluster',
            'qolByCluster', 'evalMetrics', 'snapshots'
        ));
    }

    /**
     * Risk Report page.
     */
    public function risk(Request $request)
    {
        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        // Risk level distribution
        $riskDist = MlResult::whereIn('id', $latestIds)
            ->select('overall_risk_level', DB::raw('COUNT(*) as count'))
            ->groupBy('overall_risk_level')
            ->pluck('count', 'overall_risk_level');

        // At-risk seniors list (HIGH risk only — CRITICAL no longer an official level)
        $atRiskSeniors = SeniorCitizen::active()
            ->join('ml_results', function ($join) use ($latestIds) {
                $join->on('senior_citizens.id', '=', 'ml_results.senior_citizen_id')
                     ->whereIn('ml_results.id', $latestIds);
            })
            ->whereIn('ml_results.overall_risk_level', ['HIGH'])
            ->select('senior_citizens.*', 'ml_results.overall_risk_level',
                     'ml_results.composite_risk', 'ml_results.cluster_name',
                     'ml_results.ic_risk', 'ml_results.env_risk', 'ml_results.func_risk')
            ->when($request->barangay, fn($q) => $q->where('barangay', $request->barangay))
            ->when($request->risk_level, fn($q) => $q->where('ml_results.overall_risk_level', strtoupper($request->risk_level)))
            ->orderByDesc('ml_results.composite_risk')
            ->paginate(25)
            ->withQueryString();

        // Barangay × risk breakdown
        $barangayRisk = SeniorCitizen::active()
            ->join('ml_results', function ($join) use ($latestIds) {
                $join->on('senior_citizens.id', '=', 'ml_results.senior_citizen_id')
                     ->whereIn('ml_results.id', $latestIds);
            })
            ->select('barangay', 'ml_results.overall_risk_level', DB::raw('COUNT(*) as count'))
            ->groupBy('barangay', 'ml_results.overall_risk_level')
            ->get()
            ->groupBy('barangay');

        // Domain risk averages
        $domainAvgs = MlResult::whereIn('id', $latestIds)
            ->selectRaw('AVG(ic_risk) as ic, AVG(env_risk) as env, AVG(func_risk) as func, AVG(composite_risk) as composite')
            ->first();

        // Top recommendations by category
        $recsByCategory = Recommendation::where('status', 'pending')
            ->select('category', DB::raw('COUNT(*) as count'))
            ->groupBy('category')
            ->orderByDesc('count')
            ->get();

        $barangays = SeniorCitizen::barangayList();

        return view('reports.risk', compact(
            'riskDist', 'atRiskSeniors', 'barangayRisk',
            'domainAvgs', 'recsByCategory', 'barangays'
        ));
    }

    /**
     * Redirect /reports/barangay to the first barangay in the list.
     */
    public function barangayIndex()
    {
        $first = SeniorCitizen::barangayList()[0];
        return redirect()->route('reports.barangay', $first);
    }

    /**
     * Barangay drill-down report page.
     */
    public function barangay(string $brgy)
    {
        $barangays = SeniorCitizen::barangayList();

        if (!in_array($brgy, $barangays, true)) {
            abort(404, 'Barangay not found.');
        }

        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        // All active seniors in this barangay
        $seniors = SeniorCitizen::active()
            ->where('barangay', $brgy)
            ->with('latestMlResult')
            ->orderBy('last_name')
            ->get();

        // Risk distribution for this barangay
        $riskDist = MlResult::whereIn('id', $latestIds)
            ->whereHas('seniorCitizen', fn($q) => $q->active()->where('barangay', $brgy))
            ->select('overall_risk_level', DB::raw('COUNT(*) as count'))
            ->groupBy('overall_risk_level')
            ->pluck('count', 'overall_risk_level');

        // Cluster distribution for this barangay
        $clusterDist = MlResult::whereIn('id', $latestIds)
            ->whereHas('seniorCitizen', fn($q) => $q->active()->where('barangay', $brgy))
            ->whereNotNull('cluster_named_id')
            ->select('cluster_named_id', 'cluster_name', DB::raw('COUNT(*) as count'))
            ->groupBy('cluster_named_id', 'cluster_name')
            ->orderBy('cluster_named_id')
            ->get();

        // Domain risk averages for this barangay
        $domainAvgs = MlResult::whereIn('id', $latestIds)
            ->whereHas('seniorCitizen', fn($q) => $q->active()->where('barangay', $brgy))
            ->selectRaw('AVG(ic_risk) as ic, AVG(env_risk) as env, AVG(func_risk) as func, AVG(composite_risk) as composite')
            ->first();

        // Urgency breakdown
        $urgentCount = MlResult::whereIn('id', $latestIds)
            ->whereHas('seniorCitizen', fn($q) => $q->active()->where('barangay', $brgy))
            ->where('priority_flag', 'urgent')
            ->count();

        // Pending recommendations for seniors in this barangay
        $pendingRecs = Recommendation::where('status', 'pending')
            ->whereHas('seniorCitizen', fn($q) => $q->active()->where('barangay', $brgy))
            ->select('category', DB::raw('COUNT(*) as count'))
            ->groupBy('category')
            ->orderByDesc('count')
            ->get();

        return view('reports.barangay', compact(
            'brgy', 'barangays', 'seniors',
            'riskDist', 'clusterDist', 'domainAvgs',
            'urgentCount', 'pendingRecs'
        ));
    }

    /**
     * Export cluster report as CSV via Maatwebsite/Excel.
     */
    public function exportCluster()
    {
        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        $data = SeniorCitizen::active()
            ->join('ml_results', function ($join) use ($latestIds) {
                $join->on('senior_citizens.id', '=', 'ml_results.senior_citizen_id')
                     ->whereIn('ml_results.id', $latestIds);
            })
            ->select(
                'senior_citizens.osca_id',
                DB::raw("CONCAT(senior_citizens.first_name, ' ', senior_citizens.last_name) as name"),
                'senior_citizens.barangay',
                DB::raw(DbHelper::ageExpr('senior_citizens.date_of_birth')),
                'senior_citizens.gender',
                'ml_results.cluster_named_id as cluster',
                'ml_results.cluster_name',
                'ml_results.overall_risk_level as risk_level',
                'ml_results.composite_risk',
                'ml_results.ic_risk',
                'ml_results.env_risk',
                'ml_results.func_risk',
                'ml_results.wellbeing_score',
                'ml_results.processed_at'
            )
            ->orderBy('ml_results.cluster_named_id')
            ->orderByDesc('ml_results.composite_risk')
            ->get();

        $filename = 'osca_cluster_report_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($data) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['OSCA ID','Name','Barangay','Age','Gender',
                'Cluster ID','Cluster Name','Risk Level','Composite Risk',
                'IC Risk','Env Risk','Func Risk','Wellbeing Score','Processed At']);
            foreach ($data as $row) {
                fputcsv($file, (array) $row);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Excel registry export — all active seniors + latest ML result.
     */
    public function exportRegistry()
    {
        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        $seniors = SeniorCitizen::active()
            ->leftJoin('ml_results', function ($join) use ($latestIds) {
                $join->on('senior_citizens.id', '=', 'ml_results.senior_citizen_id')
                     ->whereIn('ml_results.id', $latestIds);
            })
            ->select(
                'senior_citizens.osca_id',
                'senior_citizens.last_name',
                'senior_citizens.first_name',
                'senior_citizens.middle_name',
                'senior_citizens.date_of_birth',
                DB::raw(DbHelper::ageExpr('senior_citizens.date_of_birth')),
                'senior_citizens.gender',
                'senior_citizens.marital_status',
                'senior_citizens.barangay',
                'senior_citizens.monthly_income_range',
                'senior_citizens.status',
                'ml_results.cluster_named_id as cluster',
                'ml_results.cluster_name',
                'ml_results.overall_risk_level as risk_level',
                'ml_results.composite_risk',
                'ml_results.ic_risk',
                'ml_results.env_risk',
                'ml_results.func_risk',
                'ml_results.wellbeing_score',
                'ml_results.priority_flag',
                'ml_results.processed_at as ml_processed_at'
            )
            ->orderBy('senior_citizens.barangay')
            ->orderBy('senior_citizens.last_name')
            ->get();

        $filename = 'osca_senior_registry_' . now()->format('Ymd_His') . '.xlsx';

        // Build array data for SimpleExcel write-through
        $rows = [];
        $rows[] = [
            'OSCA ID', 'Last Name', 'First Name', 'Middle Name',
            'Date of Birth', 'Age', 'Gender', 'Marital Status', 'Barangay',
            'Monthly Income Range', 'Status',
            'Cluster', 'Cluster Name', 'Risk Level',
            'Composite Risk', 'IC Risk', 'Env Risk', 'Func Risk',
            'Wellbeing Score', 'Priority Flag', 'ML Processed At',
        ];

        foreach ($seniors as $s) {
            $rows[] = [
                $s->osca_id, $s->last_name, $s->first_name, $s->middle_name,
                $s->date_of_birth, $s->age, $s->gender, $s->marital_status, $s->barangay,
                $s->monthly_income_range, $s->status,
                $s->cluster, $s->cluster_name, $s->risk_level,
                $s->composite_risk, $s->ic_risk, $s->env_risk, $s->func_risk,
                $s->wellbeing_score, $s->priority_flag, $s->ml_processed_at,
            ];
        }

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\ArrayExport($rows),
            $filename
        );
    }

    /**
     * Trigger an on-demand cluster snapshot (POST from the cluster report page).
     */
    public function snapshotClusters(Request $request)
    {
        $today    = now()->toDateString();
        $existing = ClusterSnapshot::whereDate('snapshot_date', $today)->exists();

        $exitCode = Artisan::call('osca:snapshot-clusters', [
            '--force' => $existing,
        ]);

        if ($exitCode !== 0) {
            return back()->with('error', 'Snapshot failed — no ML results found. Run Batch Analysis first.');
        }

        return back()->with('success', "Cluster snapshot saved for {$today}.");
    }

    /**
     * Export risk report as CSV.
     */
    public function exportRisk()
    {
        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        $data = SeniorCitizen::active()
            ->join('ml_results', function ($join) use ($latestIds) {
                $join->on('senior_citizens.id', '=', 'ml_results.senior_citizen_id')
                     ->whereIn('ml_results.id', $latestIds);
            })
            ->whereIn('ml_results.overall_risk_level', ['HIGH'])
            ->select(
                'senior_citizens.osca_id',
                DB::raw("CONCAT(senior_citizens.first_name,' ',senior_citizens.last_name) as name"),
                'senior_citizens.barangay',
                DB::raw(DbHelper::ageExpr('senior_citizens.date_of_birth')),
                'ml_results.overall_risk_level',
                'ml_results.composite_risk',
                'ml_results.ic_risk_level',
                'ml_results.env_risk_level',
                'ml_results.func_risk_level',
                'ml_results.processed_at'
            )
            ->orderByDesc('ml_results.composite_risk')
            ->get();

        $filename = 'osca_risk_report_' . now()->format('Ymd_His') . '.csv';

        $callback = function () use ($data) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['OSCA ID','Name','Barangay','Age','Risk Level',
                'Composite Risk','IC Risk Level','Env Risk Level','Func Risk Level','Processed At']);
            foreach ($data as $row) {
                fputcsv($file, (array) $row);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
