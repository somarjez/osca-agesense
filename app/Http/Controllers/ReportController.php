<?php

namespace App\Http\Controllers;

use App\Exports\RegistryExport;
use App\Models\ActivityLog;
use App\Models\ClusterSnapshot;
use App\Models\Facility;
use App\Models\MlResult;
use App\Models\Recommendation;
use App\Models\SeniorAccessibilityMetric;
use App\Models\SeniorCitizen;
use App\Services\ClusterAnalyticsService;
use App\Services\DatabaseBackupService;
use App\Support\ClusterMetrics;
use App\Support\DbHelper;
use App\Support\SeniorDataVersion;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function __construct(private ClusterAnalyticsService $clusterAnalytics) {}

    /**
     * GIS Analytics landing page.
     */
    public function gis()
    {
        $this->authorize('viewAny', SeniorCitizen::class);

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

        // Senior-count-per-barangay, risk distribution, and facility-accessibility
        // averages for the GIS Analytics summary cards. Cached under the same
        // version stamp the GeoJSON feed uses (bumped by runGisGeocode() and the
        // senior/location observers), so repeat page loads skip these grouped
        // queries entirely until the underlying data actually changes.
        $summary = Cache::remember(
            'gis.summary.'.SeniorDataVersion::current(),
            now()->addMinutes(5),
            fn () => $this->gisSummaryAggregates($mappedCount)
        );

        return view('reports.gis', array_merge(
            compact('stats', 'geocodeStatus'),
            $summary
        ));
    }

    /**
     * Senior-count-per-barangay (with each barangay's dominant risk level),
     * overall risk distribution, and average facility-accessibility distances
     * across active seniors — the data behind the GIS Analytics summary cards.
     */
    private function gisSummaryAggregates(int $totalSeniors): array
    {
        // Cached, active-filtered via whereHas (no giant senior-id bind list) — see
        // ClusterAnalyticsService::latestResultIds().
        $latestMlIds = $this->clusterAnalytics->latestResultIds();

        $countsByBarangay = SeniorCitizen::active()
            ->select('barangay', DB::raw('COUNT(*) as count'))
            ->groupBy('barangay')
            ->orderByDesc('count')
            ->get();

        $riskRows = MlResult::whereIn('id', $latestMlIds)
            ->whereHas('seniorCitizen', fn ($q) => $q->active())
            ->with('seniorCitizen:id,barangay')
            ->get(['id', 'senior_citizen_id', 'overall_risk_level']);

        $riskCountsByBarangay = [];
        $riskTotals = ['HIGH' => 0, 'MODERATE' => 0, 'LOW' => 0];

        foreach ($riskRows as $row) {
            $barangay = $row->seniorCitizen->barangay ?? 'Unknown';
            $level = strtoupper((string) $row->overall_risk_level);
            if ($level === 'CRITICAL') {
                $level = 'HIGH'; // CRITICAL is no longer an official level (see <x-risk-badge>)
            }
            if (! in_array($level, ['HIGH', 'MODERATE', 'LOW'], true)) {
                continue;
            }

            $riskCountsByBarangay[$barangay][$level] = ($riskCountsByBarangay[$barangay][$level] ?? 0) + 1;
            $riskTotals[$level]++;
        }

        $barangayCounts = $countsByBarangay->map(fn ($row) => [
            'barangay' => $row->barangay,
            'count' => (int) $row->count,
            'percent' => $totalSeniors > 0 ? round($row->count / $totalSeniors * 100, 1) : 0.0,
            'dominant_risk' => $this->dominantRiskLevel($riskCountsByBarangay[$row->barangay] ?? []),
            'high_risk_count' => $riskCountsByBarangay[$row->barangay]['HIGH'] ?? 0,
        ])->values()->all();

        $riskDistribution = [
            'low' => $riskTotals['LOW'],
            'moderate' => $riskTotals['MODERATE'],
            'high' => $riskTotals['HIGH'],
            'total' => array_sum($riskTotals),
        ];

        // Average distance from the latest accessibility snapshot of each active
        // senior (barangay-level approximate coordinates, not exact addresses).
        $latestAccessibilityIds = SeniorAccessibilityMetric::select(DB::raw('MAX(id) as id'))
            ->whereHas('seniorCitizen', fn ($q) => $q->active())
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        $avg = SeniorAccessibilityMetric::whereIn('id', $latestAccessibilityIds)
            ->selectRaw('AVG(distance_to_health_center_m) as health_center_m')
            ->selectRaw('AVG(distance_to_barangay_hall_m) as barangay_hall_m')
            ->selectRaw('AVG(distance_to_pharmacy_m) as pharmacy_m')
            ->first();

        $toKm = fn (?float $meters) => $meters !== null ? round($meters / 1000, 2) : null;

        $facilityAccessibility = [
            'health_center_km' => $toKm($avg?->health_center_m),
            'barangay_hall_km' => $toKm($avg?->barangay_hall_m),
            'pharmacy_km' => $toKm($avg?->pharmacy_m),
        ];

        return compact('barangayCounts', 'riskDistribution', 'facilityAccessibility');
    }

    private function dominantRiskLevel(array $counts): ?string
    {
        $best = null;
        $bestCount = 0;

        foreach (['HIGH', 'MODERATE', 'LOW'] as $level) {
            $count = $counts[$level] ?? 0;
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $level;
            }
        }

        return $best;
    }

    /**
     * Admin action to run the privacy-safe barangay-level geocoder.
     */
    public function runGisGeocode()
    {
        // Synchronous (not ::queue): the status badge below is recomputed from
        // a live DB read immediately after this returns, so the assignment has
        // to be done by then or the badge shows stale "Needs Update" until a
        // second click/refresh. This step is local and quick (no external API
        // calls) — see the "local and quick" comment in GeocodeSeniors::handle().
        // The slow, rate-limited ORS route-distance step still runs in the
        // background: the command queues it internally, unaffected by this call
        // being synchronous.
        Artisan::call('gis:geocode');

        // Bump the version stamp folded into the GIS GeoJSON cache keys so the
        // map doesn't wait out its TTL to notice the new coordinates — same
        // pattern as SeniorLocationObserver. (The old Cache::forget() calls here
        // targeted literal keys that GisApiController never uses; it reads
        // version-stamped keys, so those forgets were a no-op.)
        SeniorDataVersion::bump();

        return back()->with('success', 'Bulk geocoding complete. The map and status now reflect the latest coordinates.');
    }

    private function gisGeocodeStatus(): array
    {
        // Was an uncached full-table active()->get() + PHP loop on every /reports/gis
        // load (measured: full row hydrate + scan at 10k records on every page view).
        // Same version-stamped cache convention as gisSummaryAggregates() above —
        // runGisGeocode() bumps SeniorDataVersion right after any geocode run, and
        // the SeniorLocationObserver bumps it on any coordinate change, so this can't
        // go stale between real updates.
        return Cache::remember(
            'gis.geocode_status.'.SeniorDataVersion::current(),
            now()->addMinutes(5),
            function () {
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
        );
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
        $this->authorize('viewAny', SeniorCitizen::class);

        // This report's aggregates are exactly ClusterAnalyticsService's cached,
        // active-filtered "latest ML result per senior" queries — delegate instead
        // of re-deriving $latestIds via a giant SeniorCitizen::active()->pluck('id')
        // bind list on every page load (measured ~4-6s at 10k records before this fix).
        $clusterSummary = $this->clusterAnalytics->clusterSummary();
        $barangayCluster = $this->clusterAnalytics->barangayClusterBreakdown();
        $domainByCluster = $this->clusterAnalytics->domainByCluster();
        $qolByCluster = $this->clusterAnalytics->qolByCluster();

        $evalMetrics = ClusterMetrics::load();

        // Snapshot history — last 30 distinct snapshot dates, newest first.
        // Fetch only those dates at DB level to avoid loading the entire table into memory.
        $dateOnly = DbHelper::dateOnlyExpr('snapshot_date');
        $recentDates = ClusterSnapshot::selectRaw("{$dateOnly} as snap_date")
            ->groupBy('snap_date')
            ->orderByDesc('snap_date')
            ->limit(30)
            ->pluck('snap_date');

        $snapshots = ClusterSnapshot::whereIn(DB::raw($dateOnly), $recentDates)
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
        $this->authorize('viewAny', SeniorCitizen::class);

        $latestIds = $this->clusterAnalytics->latestResultIds();

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
        $recsByCategory = Recommendation::current()->where('status', 'pending')
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
        $this->authorize('viewAny', SeniorCitizen::class);

        $first = SeniorCitizen::barangayList()[0];

        return redirect()->route('reports.barangay', $first);
    }

    /**
     * Barangay drill-down report page.
     */
    public function barangay(Request $request, string $brgy)
    {
        $this->authorize('viewAny', SeniorCitizen::class);

        $barangays = SeniorCitizen::barangayList();

        if (! in_array($brgy, $barangays, true)) {
            abort(404, 'Barangay not found.');
        }

        $latestIds = $this->clusterAnalytics->latestResultIds();

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
                    ->orWhere('official_osca_id', 'like', "%{$term}%")
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
        $pendingRecs = Recommendation::current()->where('status', 'pending')
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
        ActivityLog::record('exported', auth()->user(), 'Cluster report CSV exported');

        $latestIds = $this->clusterAnalytics->latestResultIds();

        $query = SeniorCitizen::active()
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
            ->orderByDesc('ml_results.composite_risk');

        $filename = 'osca_cluster_report_'.now()->format('Ymd_His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($query) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['System ID', 'Name', 'Barangay', 'Age', 'Gender',
                'Profile Group ID', 'Profile Group Name', 'Risk Level', 'Composite Risk',
                'IC Risk', 'Env Risk', 'Func Risk', 'Wellbeing Score', 'Processed At']);

            $query->chunk(200, function ($rows) use ($file) {
                foreach ($rows as $row) {
                    fputcsv($file, array_values($row->toArray()));
                }
            });

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
        ActivityLog::record('exported', auth()->user(), 'GIS report CSV exported');

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
                'Profile Group Label',
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

    /**
     * Registry and Backup landing page — summary previews + a sample of the
     * registry with a button to download the full XLSX, plus database backup
     * create/download/delete. Admin only.
     */
    public function registryIndex(DatabaseBackupService $backupService)
    {
        // Was previously computed over ALL seniors (including inactive/archived)
        // then filtered with whereHas below — the cached, active-scoped service list
        // is both correct-by-construction and reused across the page's other queries.
        $latestIds = $this->clusterAnalytics->latestResultIds();

        $total = SeniorCitizen::active()->count();
        $assessed = SeniorCitizen::active()->whereHas('latestMlResult')->count();

        $riskBreakdown = MlResult::whereIn('id', $latestIds)
            ->select('overall_risk_level', DB::raw('COUNT(*) as count'))
            ->groupBy('overall_risk_level')
            ->pluck('count', 'overall_risk_level');

        $barangaysCovered = SeniorCitizen::active()->distinct('barangay')->count('barangay');

        $preview = SeniorCitizen::active()
            ->with('latestMlResult')
            ->orderBy('barangay')
            ->orderBy('last_name')
            ->limit(12)
            ->get();

        $stats = [
            'total' => $total,
            'assessed' => $assessed,
            'not_assessed' => $total - $assessed,
            'high' => (int) ($riskBreakdown['HIGH'] ?? 0),
            'moderate' => (int) ($riskBreakdown['MODERATE'] ?? 0),
            'low' => (int) ($riskBreakdown['LOW'] ?? 0),
            'barangays' => $barangaysCovered,
        ];

        $backups = $backupService->list();

        return view('reports.registry', compact('stats', 'preview', 'backups'));
    }

    public function exportRegistry()
    {
        ActivityLog::record('exported', auth()->user(), 'Registry Excel exported');

        $filename = 'osca_senior_registry_'.now()->format('Ymd_His').'.xlsx';

        return Excel::download(new RegistryExport, $filename);
    }

    /**
     * Create an on-demand full database backup via mysqldump (POST from the
     * Registry and Backup page). Keeps only the latest 3 app-created backups —
     * see DatabaseBackupService::rotate(). Admin only.
     */
    public function createBackup(DatabaseBackupService $backupService)
    {
        try {
            $filename = $backupService->create();
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Backup failed: '.$e->getMessage());
        }

        ActivityLog::record('backup', auth()->user(), "Database backup created: {$filename}");

        return back()->with('success', "Backup created ({$filename}). Keeping the latest ".DatabaseBackupService::DEFAULT_KEEP.'.');
    }

    /**
     * Download one of the latest app-created database backups. Admin only.
     * Contains full unencrypted senior-citizen PII (except the 3 fields
     * encrypted at rest) — every download is activity-logged.
     */
    public function downloadBackup(string $file, DatabaseBackupService $backupService)
    {
        $path = $backupService->resolvePath($file);

        ActivityLog::record('exported', auth()->user(), "Database backup downloaded: {$file}");

        return response()->download($path, $file);
    }

    /**
     * Permanently delete one app-created database backup. Admin only.
     */
    public function destroyBackup(string $file, DatabaseBackupService $backupService)
    {
        $deleted = $backupService->delete($file);

        if ($deleted) {
            ActivityLog::record('backup_deleted', auth()->user(), "Database backup deleted: {$file}");
        }

        return back()->with(
            $deleted ? 'success' : 'error',
            $deleted ? "Backup {$file} permanently deleted." : "Could not delete {$file}."
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

        return back()->with('success', "Profile group snapshot saved for {$today}.");
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
        ActivityLog::record('exported', auth()->user(), 'Risk report CSV exported');

        $latestIds = $this->clusterAnalytics->latestResultIds();

        $allowedSorts = ['composite_risk', 'overall_risk_level', 'ic_risk', 'env_risk', 'func_risk', 'wellbeing_score'];
        $sortBy = in_array($request->sort, $allowedSorts, true) ? $request->sort : 'composite_risk';
        $sortDir = $request->dir === 'asc' ? 'asc' : 'desc';

        $query = SeniorCitizen::active()
            ->join('ml_results', function ($join) use ($latestIds) {
                $join->on('senior_citizens.id', '=', 'ml_results.senior_citizen_id')
                    ->whereIn('ml_results.id', $latestIds);
            })
            ->when($request->risk, fn ($q, $risk) => $q->where('ml_results.overall_risk_level', strtoupper($risk)))
            ->when($request->barangay, fn ($q, $b) => $q->where('senior_citizens.barangay', $b))
            ->when($request->cluster, fn ($q, $c) => $q->where('ml_results.cluster_named_id', $c))
            ->when($request->search, fn ($q, $term) => $q->where(function ($w) use ($term) {
                $w->where('senior_citizens.osca_id', 'like', "%{$term}%")
                    ->orWhere('senior_citizens.official_osca_id', 'like', "%{$term}%")
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
            ->orderBy("ml_results.{$sortBy}", $sortDir);

        $filename = 'osca_risk_report_'.now()->format('Ymd_His').'.csv';

        $callback = function () use ($query) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['System ID', 'Name', 'Barangay', 'Age', 'Risk Level',
                'Composite Risk', 'IC Risk Level', 'Env Risk Level', 'Func Risk Level', 'Processed At']);

            $query->chunk(200, function ($rows) use ($file) {
                foreach ($rows as $row) {
                    fputcsv($file, array_values($row->toArray()));
                }
            });

            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
