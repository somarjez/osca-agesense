<?php

namespace App\Http\Controllers;

use App\Models\MlResult;
use App\Models\QolSurvey;
use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeniorCitizenController extends Controller
{
    public function index(Request $request)
    {
        $query = SeniorCitizen::active()
            ->with(['latestMlResult'])
            ->when($request->search, fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'like', "%{$request->search}%")
                ->orWhere('last_name', 'like', "%{$request->search}%")
                ->orWhere('osca_id', 'like', "%{$request->search}%")
            ))
            ->when($request->barangay, fn ($q) => $q->where('barangay', $request->barangay))
            ->when($request->risk, fn ($q) => $q->byRiskLevel($request->risk))
            ->when($request->cluster, fn ($q) => $q->whereHas('latestMlResult', fn ($m) => $m->where('cluster_named_id', (int) $request->cluster)
            ))
            ->latest();

        $seniors = $query->paginate(20)->withQueryString();
        $barangays = SeniorCitizen::barangayList();

        // Restrict stats to active (non-archived) seniors only
        $activeSeniorIds = SeniorCitizen::active()->pluck('id');
        $latestActiveMlIds = MlResult::select(DB::raw('MAX(id)'))
            ->whereIn('senior_citizen_id', $activeSeniorIds)
            ->groupBy('senior_citizen_id');

        $stats = [
            'total' => $activeSeniorIds->count(),
            'urgent' => MlResult::where('priority_flag', 'urgent')
                ->whereIn('id', $latestActiveMlIds)
                ->count(),
            'high' => MlResult::where('overall_risk_level', 'HIGH')
                ->whereIn('id', $latestActiveMlIds)
                ->count(),
        ];

        return view('seniors.index', compact('seniors', 'barangays', 'stats'));
    }

    public function create()
    {
        return view('seniors.create');
    }

    public function store(Request $request)
    {
        return redirect()->route('seniors.index');
    }

    public function show(SeniorCitizen $senior)
    {
        $senior->load([
            'qolSurveys' => fn ($q) => $q->latest()->limit(5),
            'latestMlResult.recommendations',
            'mlResults' => fn ($q) => $q->latest()->limit(3),
            // Accessibility panel — all read from cache, never a live ORS call.
            'latestAccessibilityMetric.nearestHealthCenter',
            'latestAccessibilityMetric.nearestHospital',
            'latestAccessibilityMetric.nearestPharmacy',
            'latestAccessibilityMetric.nearestBarangayHall',
            'latestAccessibilityMetric.nearestMarket',
            'facilityRouteDistances',
        ]);

        $draftSurvey = $senior->qolSurveys()->where('status', 'draft')->latest()->first();
        $locationPanel = $this->locationPanel($senior);

        return view('seniors.show', compact('senior', 'draftSurvey', 'locationPanel'));
    }

    /**
     * Assemble the profile's "Location & Accessibility" view-model.
     *
     * Every value here is read from already-computed tables:
     *   - senior_accessibility_metrics  (local haversine distances + score)
     *   - senior_facility_route_distances (ORS road distances, precomputed in bulk)
     * The profile therefore renders with zero live OpenRouteService calls.
     */
    private function locationPanel(SeniorCitizen $senior): array
    {
        $metric = $senior->latestAccessibilityMetric;
        $routeByFacility = $senior->facilityRouteDistances->keyBy('facility_id');
        $seniorLat = $senior->latitude !== null ? (float) $senior->latitude : null;
        $seniorLng = $senior->longitude !== null ? (float) $senior->longitude : null;

        $definitions = [
            ['key' => 'health_center', 'label' => 'Health Center', 'relation' => 'nearestHealthCenter', 'distance' => 'distance_to_health_center_m'],
            ['key' => 'hospital',      'label' => 'Hospital',      'relation' => 'nearestHospital',      'distance' => 'distance_to_hospital_m'],
            ['key' => 'pharmacy',      'label' => 'Pharmacy',      'relation' => 'nearestPharmacy',      'distance' => 'distance_to_pharmacy_m'],
            ['key' => 'barangay_hall', 'label' => 'Barangay Hall', 'relation' => 'nearestBarangayHall',  'distance' => 'distance_to_barangay_hall_m'],
            ['key' => 'market',        'label' => 'Public Market', 'relation' => 'nearestMarket',        'distance' => 'distance_to_market_m'],
        ];

        $facilities = [];

        if ($metric) {
            foreach ($definitions as $definition) {
                $facility = $metric->{$definition['relation']};
                if (! $facility || $facility->latitude === null || $facility->longitude === null) {
                    continue;
                }

                $facilityLat = (float) $facility->latitude;
                $facilityLng = (float) $facility->longitude;
                $straight = $metric->{$definition['distance']};

                // Only trust a cached road distance whose endpoints still match the
                // senior's and facility's current coordinates — same staleness
                // contract GisApiController uses before serving a cached route.
                $route = $routeByFacility->get($facility->id);
                $routeFresh = $route
                    && $seniorLat !== null && $seniorLng !== null
                    && $this->coordinatesMatch($route->origin_latitude, $seniorLat)
                    && $this->coordinatesMatch($route->origin_longitude, $seniorLng)
                    && $this->coordinatesMatch($route->destination_latitude, $facilityLat)
                    && $this->coordinatesMatch($route->destination_longitude, $facilityLng);

                $facilities[] = [
                    'key' => $definition['key'],
                    'label' => $definition['label'],
                    'name' => $facility->name,
                    'lat' => $facilityLat,
                    'lng' => $facilityLng,
                    'straight_m' => $straight !== null ? (float) $straight : null,
                    'route_m' => $routeFresh ? (float) $route->route_distance_m : null,
                    'route_s' => $routeFresh && $route->route_duration_s !== null ? (int) round($route->route_duration_s) : null,
                ];
            }

            // Nearest first by straight-line distance; missing distances sink last.
            usort($facilities, fn ($a, $b) => ($a['straight_m'] ?? INF) <=> ($b['straight_m'] ?? INF));
        }

        $score = $metric && $metric->accessibility_score !== null ? (float) $metric->accessibility_score : null;
        $percent = $score !== null
            ? (int) round(max(0, min(100, $score <= 1 ? $score * 100 : $score)))
            : null;

        return [
            'location' => $senior->locationDisplay(),
            'facilities' => $facilities,
            'percent' => $percent,
            'status' => $this->accessibilityStatusLabel($percent),
        ];
    }

    /**
     * Two stored coordinate values refer to the same point (within rounding).
     * Mirrors GisApiController's 1e-6 tolerance for cached-route freshness.
     */
    private function coordinatesMatch(mixed $stored, float $current): bool
    {
        return $stored !== null && abs((float) $stored - $current) <= 0.000001;
    }

    /**
     * Plain-language band for an accessibility percentage.
     * Matches the thresholds GisApiController uses on the map.
     */
    private function accessibilityStatusLabel(?int $percent): ?string
    {
        if ($percent === null) {
            return null;
        }

        return match (true) {
            $percent >= 75 => 'Good access',
            $percent >= 50 => 'Moderate access',
            default => 'Needs attention',
        };
    }

    public function edit(SeniorCitizen $senior)
    {
        return view('seniors.edit', compact('senior'));
    }

    public function destroy(SeniorCitizen $senior)
    {
        // Cascade soft-delete: recommendations → ml_results → surveys → senior
        Recommendation::where('senior_citizen_id', $senior->id)->delete();
        MlResult::where('senior_citizen_id', $senior->id)->delete();
        $senior->qolSurveys()->each(fn ($s) => $s->delete());
        $senior->delete();

        return redirect()->route('seniors.index')->with('success', 'Senior record archived.');
    }

    public function bulkDestroy(Request $request)
    {
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));
        if (empty($ids)) {
            return back()->with('error', 'No records selected.');
        }
        $seniors = SeniorCitizen::whereIn('id', $ids)->get();
        foreach ($seniors as $senior) {
            Recommendation::where('senior_citizen_id', $senior->id)->delete();
            MlResult::where('senior_citizen_id', $senior->id)->delete();
            $senior->qolSurveys()->each(fn ($s) => $s->delete());
            $senior->delete();
        }
        $count = $seniors->count();

        return redirect()->route('seniors.index')->with('success', "{$count} senior record(s) archived.");
    }

    public function bulkRestore(Request $request)
    {
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));
        if (empty($ids)) {
            return back()->with('error', 'No records selected.');
        }
        $seniors = SeniorCitizen::onlyTrashed()->whereIn('id', $ids)->get();
        foreach ($seniors as $senior) {
            QolSurvey::onlyTrashed()
                ->where('senior_citizen_id', $senior->id)
                ->each(fn ($s) => $s->restore());
            $senior->restore();
        }
        $count = $seniors->count();

        return redirect()->route('seniors.archives')->with('success', "{$count} senior record(s) restored.");
    }

    public function bulkForceDestroy(Request $request)
    {
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));
        if (empty($ids)) {
            return back()->with('error', 'No records selected.');
        }
        $seniors = SeniorCitizen::onlyTrashed()->whereIn('id', $ids)->get();
        foreach ($seniors as $senior) {
            foreach ($senior->qolSurveys()->withTrashed()->get() as $survey) {
                if ($survey->mlResult) {
                    $survey->mlResult->recommendations()->delete();
                    $survey->mlResult->delete();
                }
                $survey->forceDelete();
            }
            $senior->mlResults()->delete();
            $senior->forceDelete();
        }
        $count = $seniors->count();

        return redirect()->route('seniors.archives')->with('success', "{$count} senior record(s) permanently deleted.");
    }

    public function archives(Request $request)
    {
        $seniors = SeniorCitizen::onlyTrashed()
            ->when($request->search, fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'like', "%{$request->search}%")
                ->orWhere('last_name', 'like', "%{$request->search}%")
                ->orWhere('osca_id', 'like', "%{$request->search}%")
            ))
            ->when($request->barangay, fn ($q) => $q->where('barangay', $request->barangay))
            ->latest('deleted_at')
            ->paginate(20)->withQueryString();

        $archivedSurveys = QolSurvey::onlyTrashed()
            ->with(['seniorCitizen' => fn ($q) => $q->withTrashed()])
            ->when($request->search, fn ($q) => $q->whereHas('seniorCitizen', fn ($q) => $q->withTrashed()->where(fn ($q) => $q->where('first_name', 'like', "%{$request->search}%")
                ->orWhere('last_name', 'like', "%{$request->search}%")
            )))
            ->when($request->barangay, fn ($q) => $q->whereHas('seniorCitizen', fn ($q) => $q->withTrashed()->where('barangay', $request->barangay))
            )
            ->latest('deleted_at')
            ->paginate(20, ['*'], 'qol_page')->withQueryString();

        $barangays = SeniorCitizen::barangayList();

        return view('seniors.archives', compact('seniors', 'archivedSurveys', 'barangays'));
    }

    public function restore(int $id)
    {
        $senior = SeniorCitizen::withTrashed()->findOrFail($id);
        // Restore all data that was soft-deleted when this senior was archived
        Recommendation::withTrashed()->where('senior_citizen_id', $senior->id)->restore();
        MlResult::withTrashed()->where('senior_citizen_id', $senior->id)->restore();
        QolSurvey::onlyTrashed()
            ->where('senior_citizen_id', $senior->id)
            ->each(fn ($s) => $s->restore());
        $senior->restore();

        return redirect()->route('seniors.archives')->with('success', 'Senior record restored to active.');
    }

    public function forceDestroy(int $id)
    {
        $senior = SeniorCitizen::withTrashed()->findOrFail($id);

        // Cascade hard-delete in dependency order: recommendations first,
        // then ml_results (withTrashed so soft-deleted rows are included),
        // then surveys, finally the senior.
        Recommendation::withTrashed()->where('senior_citizen_id', $senior->id)->forceDelete();
        MlResult::withTrashed()->where('senior_citizen_id', $senior->id)->forceDelete();
        $senior->qolSurveys()->withTrashed()->each(fn ($s) => $s->forceDelete());
        $senior->forceDelete();

        return redirect()->route('seniors.archives')->with('success', 'Senior record and all related data permanently deleted.');
    }

    public function export(SeniorCitizen $senior)
    {
        $senior->load(['latestMlResult.recommendations', 'latestQolSurvey']);
        $pdf = Pdf::loadView('seniors.pdf', compact('senior'))
            ->setPaper('a4', 'portrait');

        return $pdf->download("osca-profile-{$senior->osca_id}.pdf");
    }
}
