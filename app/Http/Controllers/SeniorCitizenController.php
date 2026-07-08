<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\MlResult;
use App\Models\QolSurvey;
use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use App\Support\AccessibilityBand;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeniorCitizenController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', SeniorCitizen::class);

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
        $this->authorize('view', $senior);

        $senior->load([
            'qolSurveys' => fn ($q) => $q->latest()->limit(5),
            'latestMlResult.recommendations',
            'mlResults' => fn ($q) => $q->latest()->limit(3),
            // Accessibility panel — score from the metric, distances computed
            // locally from the Facility table; cached routes only, never a live ORS call.
            'latestAccessibilityMetric',
            'facilityRouteDistances',
        ]);

        $draftSurvey = $senior->qolSurveys()->where('status', 'draft')->latest()->first();
        $locationPanel = $this->locationPanel($senior);

        return view('seniors.show', compact('senior', 'draftSurvey', 'locationPanel'));
    }

    /** Max number of facility types the profile's Location panel lists (nearest per type). */
    private const NEAREST_FACILITY_LIMIT = 10;

    /**
     * Keywords for services that matter to a senior's access. Mirrors the GIS
     * map's SENIOR_RELEVANT_FACILITY_PRIORITY so both surfaces agree on what
     * counts as a "senior service" (excludes sari-sari stores, eateries, etc.).
     * Substring match, so 'market' also covers "Supermarket".
     */
    private const SENIOR_RELEVANT_KEYWORDS = [
        'health center', 'hospital', 'clinic', 'rural health',
        'pharmacy', 'drugstore', 'medicine',
        'senior center', 'senior citizens', 'osca',
        'barangay hall', 'municipal hall',
        'public market', 'market', 'transport hub', 'terminal',
        'church', 'chapel',
    ];

    /**
     * Assemble the profile's "Location & Accessibility" view-model.
     *
     * Reads only already-computed data, so the page renders with zero live
     * OpenRouteService calls:
     *   - senior_accessibility_metrics    (accessibility score)
     *   - the Facility table              (nearest senior-relevant facility per
     *                                      type, ranked by local haversine)
     *   - senior_facility_route_distances (cached ORS road distance, if fresh)
     *
     * Facilities without a fresh cached road route fall back to straight-line
     * here and carry the `facility_id`; the profile view then lazily upgrades
     * those rows to road distance client-side (after render) via the same
     * /api/gis/route-distance proxy the GIS map popup uses, persisting the
     * result so the next render serves it from cache.
     */
    private function locationPanel(SeniorCitizen $senior): array
    {
        $metric = $senior->latestAccessibilityMetric;
        $seniorLat = $senior->latitude !== null ? (float) $senior->latitude : null;
        $seniorLng = $senior->longitude !== null ? (float) $senior->longitude : null;

        $facilities = [];

        if ($seniorLat !== null && $seniorLng !== null) {
            $routeByFacility = $senior->facilityRouteDistances->keyBy('facility_id');

            // The nearest facility of each senior-relevant type (one per type),
            // ordered by distance — mirrors the GIS map popup so both surfaces
            // draw from the same set. Haversine is computed locally over the
            // Facility table, so the profile still makes zero live routing calls.
            $ranked = Facility::query()
                ->where('is_active', true)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->get(['id', 'name', 'type', 'barangay', 'latitude', 'longitude'])
                ->filter(fn ($facility) => $this->isSeniorRelevantFacility($facility))
                ->each(fn ($facility) => $facility->straight_m = $this->haversineMeters(
                    $seniorLat, $seniorLng, (float) $facility->latitude, (float) $facility->longitude
                ))
                ->sortBy('straight_m')
                ->groupBy('type')                     // one entry per facility type…
                ->map(fn ($group) => $group->first()) // …the nearest of that type
                ->sortBy('straight_m')                // order the winners by distance
                ->take(self::NEAREST_FACILITY_LIMIT)
                ->values();

            foreach ($ranked as $facility) {
                $facilityLat = (float) $facility->latitude;
                $facilityLng = (float) $facility->longitude;

                // Only trust a cached road distance whose endpoints still match the
                // senior's and facility's current coordinates — same staleness
                // contract GisApiController uses before serving a cached route.
                $route = $routeByFacility->get($facility->id);
                $routeFresh = $route
                    && $this->coordinatesMatch($route->origin_latitude, $seniorLat)
                    && $this->coordinatesMatch($route->origin_longitude, $seniorLng)
                    && $this->coordinatesMatch($route->destination_latitude, $facilityLat)
                    && $this->coordinatesMatch($route->destination_longitude, $facilityLng);

                $facilities[] = [
                    'facility_id' => $facility->id,
                    'key' => $this->facilityTypeKey($facility->type),
                    'label' => $facility->type ?: 'Service',
                    'name' => $facility->name ?: ($facility->type ?: 'Senior service'),
                    'lat' => $facilityLat,
                    'lng' => $facilityLng,
                    'straight_m' => (float) $facility->straight_m,
                    'route_m' => $routeFresh ? (float) $route->route_distance_m : null,
                    'route_s' => $routeFresh && $route->route_duration_s !== null ? (int) round($route->route_duration_s) : null,
                ];
            }
        }

        $score = $metric && $metric->accessibility_score !== null ? (float) $metric->accessibility_score : null;
        $percent = $score !== null
            ? (int) round(max(0, min(100, $score <= 1 ? $score * 100 : $score)))
            : null;

        $band = AccessibilityBand::classify($percent);

        return [
            'location' => $senior->locationDisplay(),
            'facilities' => $facilities,
            'percent' => $percent,
            'status' => $band['label'] ?? null,
            'band' => $band,
        ];
    }

    /** Whether a facility is a senior-relevant service (type or name keyword). */
    private function isSeniorRelevantFacility(Facility $facility): bool
    {
        $text = strtolower(trim(($facility->type ?? '').' '.($facility->name ?? '')));

        foreach (self::SENIOR_RELEVANT_KEYWORDS as $keyword) {
            if ($text !== '' && str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /** Great-circle distance in metres between two lat/lng points. */
    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Map a Facility `type` to the icon key the profile view understands. */
    private function facilityTypeKey(?string $type): string
    {
        return match ($type) {
            'Health Center' => 'health_center',
            'Hospital' => 'hospital',
            'Pharmacy' => 'pharmacy',
            'Barangay Hall' => 'barangay_hall',
            'Municipal Hall' => 'municipal_hall',
            'Government Office' => 'gov_office',
            'Public Market' => 'market',
            'Supermarket' => 'supermarket',
            'Community Store' => 'store',
            'Food Service' => 'food',
            'Church' => 'church',
            'Police Station' => 'police',
            'Fire Station' => 'fire',
            'Senior Center' => 'senior_center',
            default => 'other',
        };
    }

    /**
     * Two stored coordinate values refer to the same point (within rounding).
     * Mirrors GisApiController's 1e-6 tolerance for cached-route freshness.
     */
    private function coordinatesMatch(mixed $stored, float $current): bool
    {
        return $stored !== null && abs((float) $stored - $current) <= 0.000001;
    }

    public function edit(SeniorCitizen $senior)
    {
        $this->authorize('update', $senior);

        return view('seniors.edit', compact('senior'));
    }

    public function destroy(SeniorCitizen $senior)
    {
        $this->authorize('delete', $senior);

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
        $this->authorize('export', $senior);

        $senior->load(['latestMlResult.recommendations', 'latestQolSurvey']);
        $pdf = Pdf::loadView('seniors.pdf', compact('senior'))
            ->setPaper('a4', 'portrait');

        return $pdf->download("osca-profile-{$senior->osca_id}.pdf");
    }
}
