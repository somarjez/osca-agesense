<?php

namespace App\Http\Controllers;

use App\Exports\ArrayExport;
use App\Models\ClusterSnapshot;
use App\Models\Facility;
use App\Models\MlResult;
use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use App\Support\ClusterMetrics;
use App\Support\DbHelper;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    /**
     * GIS Analytics landing page.
     */
    public function gis()
    {
        $mappedCount = SeniorCitizen::active()->count();
        $highRiskMapped = SeniorCitizen::active()
            ->whereHas('latestMlResult', fn ($q) => $q->where('overall_risk_level', 'HIGH'))
            ->count();
        $geocodeStatus = $this->gisGeocodeStatus();

        $stats = [
            'mapped_seniors' => $mappedCount,
            'high_risk_mapped' => $highRiskMapped,
            'barangays_covered' => SeniorCitizen::active()->distinct('barangay')->count('barangay'),
            'facilities_recorded' => Facility::query()->count(),
        ];

        return view('reports.gis', compact('stats', 'geocodeStatus'));
    }

    /**
     * Admin action to run the privacy-safe barangay-level geocoder.
     */
    public function runGisGeocode()
    {
        Artisan::queue('gis:geocode');
        Cache::forget('gis.seniors_geojson');

        return back()->with('success', 'Geocoding job queued. Coordinates will update within a few minutes — refresh the GIS map to see the results.');
    }

    private function gisGeocodeStatus(): array
    {
        $seniors = SeniorCitizen::active()
            ->get(['latitude', 'longitude', 'location_source', 'location_accuracy']);

        $total = $seniors->count();
        $verified = 0;
        $approximate = 0;
        $missing = 0;

        foreach ($seniors as $senior) {
            if (! $this->hasValidGisCoordinates($senior->latitude, $senior->longitude)) {
                $missing++;

                continue;
            }

            if ($this->isVerifiedGisCoordinate($senior->location_source, $senior->location_accuracy)) {
                $verified++;

                continue;
            }

            $approximate++;
        }

        $lastRunAt = $this->lastGisGeocodeRunAt();
        $status = 'Pending';
        if ($total > 0 && $missing === 0) {
            $status = 'Completed';
        } elseif ($approximate > 0 || $verified > 0 || $lastRunAt !== null) {
            $status = 'Needs Update';
        }

        return [
            'coordinate_mode' => 'Barangay-level approximate',
            'total_seniors' => $total,
            'approximate_coordinates' => $approximate,
            'verified_coordinates' => $verified,
            'missing_coordinates' => $missing,
            'last_run_at' => $lastRunAt,
            'status' => $status,
        ];
    }

    private function lastGisGeocodeRunAt(): ?string
    {
        $path = 'gis/geocode_status.json';
        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        $decoded = json_decode(Storage::disk('local')->get($path), true);
        $lastRunAt = $decoded['last_run_at'] ?? null;

        if (! $lastRunAt) {
            return null;
        }

        try {
            return Carbon::parse($lastRunAt)->timezone(config('app.timezone'))->format('M j, Y g:i A');
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasValidGisCoordinates(mixed $latitude, mixed $longitude): bool
    {
        if ($latitude === null || $longitude === null || $latitude === '' || $longitude === '') {
            return false;
        }

        $lat = filter_var($latitude, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($longitude, FILTER_VALIDATE_FLOAT);

        return $lat !== false
            && $lng !== false
            && $lat >= -90
            && $lat <= 90
            && $lng >= -180
            && $lng <= 180
            && abs((float) $lat) >= 0.000001
            && abs((float) $lng) >= 0.000001;
    }

    private function isVerifiedGisCoordinate(mixed $source, mixed $accuracy): bool
    {
        $source = strtolower((string) $source);
        $accuracy = strtolower((string) $accuracy);

        return in_array($source, ['manual_pin', 'gps_capture'], true)
            || str_contains($accuracy, 'verified')
            || str_contains($accuracy, 'manual');
    }

    /**
     * Cluster Analysis report page.
     */
    public function cluster(Request $request)
    {
        // Latest ML result per active senior only
        $activeSeniorIds = SeniorCitizen::active()->pluck('id');
        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->whereIn('senior_citizen_id', $activeSeniorIds)
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

        $evalMetrics = ClusterMetrics::load();

        // Snapshot history — last 30 distinct snapshot dates, newest first.
        // Fetch only those dates at DB level to avoid loading the entire table into memory.
        $recentDates = ClusterSnapshot::selectRaw('DATE(snapshot_date) as snap_date')
            ->groupBy('snap_date')
            ->orderByDesc('snap_date')
            ->limit(30)
            ->pluck('snap_date');

        $snapshots = ClusterSnapshot::whereIn(DB::raw('DATE(snapshot_date)'), $recentDates)
            ->orderByDesc('snapshot_date')
            ->orderBy('cluster_id')
            ->get()
            ->groupBy(fn ($s) => $s->snapshot_date->format('Y-m-d'));

        return view('reports.cluster', compact(
            'clusterSummary', 'barangayCluster', 'domainByCluster',
            'qolByCluster', 'evalMetrics', 'snapshots'
        ));
    }

    /**
     * Risk Report page.
     */
    public function risk()
    {
        $activeSeniorIds = SeniorCitizen::active()->pluck('id');
        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->whereIn('senior_citizen_id', $activeSeniorIds)
            ->groupBy('senior_citizen_id')
            ->pluck('id');

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

        return view('reports.risk', compact(
            'barangayRisk', 'domainAvgs', 'recsByCategory'
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
    public function barangay(Request $request, string $brgy)
    {
        $barangays = SeniorCitizen::barangayList();

        if (! in_array($brgy, $barangays, true)) {
            abort(404, 'Barangay not found.');
        }

        $activeSeniorIds = SeniorCitizen::active()->pluck('id');
        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->whereIn('senior_citizen_id', $activeSeniorIds)
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        // All active seniors in this barangay (drives the barangay-wide KPIs/aggregates)
        $seniors = SeniorCitizen::active()
            ->where('barangay', $brgy)
            ->with('latestMlResult')
            ->orderBy('last_name')
            ->get();

        // Roster table — paginated + searchable (independent of the barangay-wide aggregates).
        $roster = SeniorCitizen::active()
            ->where('barangay', $brgy)
            ->with('latestMlResult')
            ->when($request->roster_search, fn ($q, $term) => $q->where(function ($w) use ($term) {
                $w->where('osca_id', 'like', "%{$term}%")
                    ->orWhereRaw("LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?", ['%'.strtolower($term).'%']);
            }))
            ->orderBy('last_name')
            ->paginate(25)
            ->withQueryString();

        // Risk distribution for this barangay
        $riskDist = MlResult::whereIn('id', $latestIds)
            ->whereHas('seniorCitizen', fn ($q) => $q->active()->where('barangay', $brgy))
            ->select('overall_risk_level', DB::raw('COUNT(*) as count'))
            ->groupBy('overall_risk_level')
            ->pluck('count', 'overall_risk_level');

        // Cluster distribution for this barangay
        $clusterDist = MlResult::whereIn('id', $latestIds)
            ->whereHas('seniorCitizen', fn ($q) => $q->active()->where('barangay', $brgy))
            ->whereNotNull('cluster_named_id')
            ->select('cluster_named_id', 'cluster_name', DB::raw('COUNT(*) as count'))
            ->groupBy('cluster_named_id', 'cluster_name')
            ->orderBy('cluster_named_id')
            ->get();

        // Domain risk averages for this barangay
        $domainAvgs = MlResult::whereIn('id', $latestIds)
            ->whereHas('seniorCitizen', fn ($q) => $q->active()->where('barangay', $brgy))
            ->selectRaw('AVG(ic_risk) as ic, AVG(env_risk) as env, AVG(func_risk) as func, AVG(composite_risk) as composite')
            ->first();

        // Urgency breakdown
        $urgentCount = MlResult::whereIn('id', $latestIds)
            ->whereHas('seniorCitizen', fn ($q) => $q->active()->where('barangay', $brgy))
            ->where('priority_flag', 'urgent')
            ->count();

        // Pending recommendations for seniors in this barangay
        $pendingRecs = Recommendation::where('status', 'pending')
            ->whereHas('seniorCitizen', fn ($q) => $q->active()->where('barangay', $brgy))
            ->select('category', DB::raw('COUNT(*) as count'))
            ->groupBy('category')
            ->orderByDesc('count')
            ->get();

        return view('reports.barangay', compact(
            'brgy', 'barangays', 'seniors', 'roster',
            'riskDist', 'clusterDist', 'domainAvgs',
            'urgentCount', 'pendingRecs'
        ));
    }

    /**
     * Export cluster report as CSV via Maatwebsite/Excel.
     */
    public function exportCluster()
    {
        $activeSeniorIds = SeniorCitizen::active()->pluck('id');
        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->whereIn('senior_citizen_id', $activeSeniorIds)
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

        $filename = 'osca_cluster_report_'.now()->format('Ymd_His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($data) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['OSCA ID', 'Name', 'Barangay', 'Age', 'Gender',
                'Cluster ID', 'Cluster Name', 'Risk Level', 'Composite Risk',
                'IC Risk', 'Env Risk', 'Func Risk', 'Wellbeing Score', 'Processed At']);
            foreach ($data as $row) {
                fputcsv($file, array_values($row->toArray()));
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Excel registry export — all active seniors + latest ML result.
     */
    /**
     * Privacy-safe GIS accessibility export for authorized OSCA administrators.
     */
    public function exportGis(Request $request)
    {
        $query = SeniorCitizen::active()
            ->with(['latestMlResult', 'latestAccessibilityMetric'])
            ->when($request->filled('barangay') && $request->barangay !== 'all', fn ($q) => $q->where('barangay', $request->barangay)
            )
            ->when($request->filled('risk') && $request->risk !== 'all', fn ($q) => $q->whereHas('latestMlResult', fn ($ml) => $ml->where('overall_risk_level', strtoupper((string) $request->risk))
            )
            )
            ->when($request->filled('cluster') && $request->cluster !== 'all', function ($q) use ($request) {
                $cluster = (string) $request->cluster;
                $clusterId = preg_match('/(\d+)/', $cluster, $matches) ? (int) $matches[1] : null;

                $q->whereHas('latestMlResult', function ($ml) use ($cluster, $clusterId) {
                    $ml->when($clusterId, fn ($sub) => $sub->where('cluster_named_id', $clusterId))
                        ->when(! $clusterId, fn ($sub) => $sub->where('cluster_name', $cluster));
                });
            })
            ->orderBy('barangay')
            ->orderBy('osca_id');

        $filename = 'osca_gis_accessibility_'.now()->format('Ymd_His').'.csv';

        $callback = function () use ($query) {
            $file = fopen('php://output', 'w');
            fputcsv($file, [
                'Anonymized Senior ID',
                'Barangay',
                'Latitude (approx, 3dp)',
                'Longitude (approx, 3dp)',
                'Location Source',
                'Location Accuracy',
                'Nearest Health Center Distance (m)',
                'Nearest Hospital Distance (m)',
                'Nearest Market Distance (m)',
                'Nearest Pharmacy Distance (m)',
                'Nearest Barangay Hall Distance (m)',
                'GIS Proximity Score',
                'Cluster Label',
                'Risk Indicator',
            ]);

            $query->chunk(200, function ($seniors) use ($file) {
                foreach ($seniors as $senior) {
                    $metric = $senior->latestAccessibilityMetric;
                    $ml = $senior->latestMlResult;
                    $score = $metric?->accessibility_score !== null
                        ? round(((float) $metric->accessibility_score) * 100, 2)
                        : null;

                    fputcsv($file, [
                        $senior->osca_id ?: 'SEN-'.str_pad((string) $senior->id, 4, '0', STR_PAD_LEFT),
                        $senior->barangay,
                        $senior->latitude !== null ? round((float) $senior->latitude, 3) : null,
                        $senior->longitude !== null ? round((float) $senior->longitude, 3) : null,
                        $senior->location_source,
                        $senior->location_accuracy,
                        $metric?->distance_to_health_center_m,
                        $metric?->distance_to_hospital_m,
                        $metric?->distance_to_market_m,
                        $metric?->distance_to_pharmacy_m,
                        $metric?->distance_to_barangay_hall_m,
                        $score,
                        $ml?->cluster_named_id ? 'Group '.$ml->cluster_named_id : ($ml?->cluster_name ?? 'Unassigned'),
                        $ml?->overall_risk_level,
                    ]);
                }
            });

            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

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

        $filename = 'osca_senior_registry_'.now()->format('Ymd_His').'.xlsx';

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

        return Excel::download(
            new ArrayExport($rows),
            $filename
        );
    }

    /**
     * Trigger an on-demand cluster snapshot (POST from the cluster report page).
     */
    public function snapshotClusters(Request $request)
    {
        $today = now()->toDateString();
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
     * Permanently delete all cluster-snapshot rows for a given date (Y-m-d).
     * ClusterSnapshot has no soft-deletes, so this is irreversible. Admin only.
     */
    public function destroySnapshot(string $date)
    {
        try {
            $parsed = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        } catch (\Throwable $e) {
            abort(404);
        }

        $deleted = ClusterSnapshot::whereDate('snapshot_date', $parsed->toDateString())->delete();

        return back()->with(
            $deleted ? 'success' : 'error',
            $deleted
                ? "Snapshot for {$parsed->format('M d, Y')} permanently deleted."
                : 'No snapshot found for that date.'
        );
    }

    /**
     * Export risk report as CSV.
     */
    public function exportRisk(Request $request)
    {
        $activeSeniorIds = SeniorCitizen::active()->pluck('id');
        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->whereIn('senior_citizen_id', $activeSeniorIds)
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        $allowedSorts = ['composite_risk', 'overall_risk_level', 'ic_risk', 'env_risk', 'func_risk', 'wellbeing_score'];
        $sortBy = in_array($request->sort, $allowedSorts, true) ? $request->sort : 'composite_risk';
        $sortDir = $request->dir === 'asc' ? 'asc' : 'desc';

        $data = SeniorCitizen::active()
            ->join('ml_results', function ($join) use ($latestIds) {
                $join->on('senior_citizens.id', '=', 'ml_results.senior_citizen_id')
                    ->whereIn('ml_results.id', $latestIds);
            })
            ->when($request->risk, fn ($q, $risk) => $q->where('ml_results.overall_risk_level', strtoupper($risk)))
            ->when($request->barangay, fn ($q, $b) => $q->where('senior_citizens.barangay', $b))
            ->when($request->cluster, fn ($q, $c) => $q->where('ml_results.cluster_named_id', $c))
            ->when($request->search, fn ($q, $term) => $q->where(function ($w) use ($term) {
                $w->where('senior_citizens.osca_id', 'like', "%{$term}%")
                    ->orWhereRaw("LOWER(CONCAT(senior_citizens.first_name,' ',senior_citizens.last_name)) LIKE ?", ['%'.strtolower($term).'%']);
            }))
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
            ->orderBy("ml_results.{$sortBy}", $sortDir)
            ->get();

        $filename = 'osca_risk_report_'.now()->format('Ymd_His').'.csv';

        $callback = function () use ($data) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['OSCA ID', 'Name', 'Barangay', 'Age', 'Risk Level',
                'Composite Risk', 'IC Risk Level', 'Env Risk Level', 'Func Risk Level', 'Processed At']);
            foreach ($data as $row) {
                fputcsv($file, array_values($row->toArray()));
            }
            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
