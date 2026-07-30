<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Facility;
use App\Models\MlResult;
use App\Models\ProfileDraft;
use App\Models\QolSurvey;
use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use App\Support\AccessibilityBand;
use App\Support\CoordinatePrivacy;
use App\Support\DbHelper;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeniorCitizenController extends Controller
{
    public function __construct(private CoordinatePrivacy $coordinatePrivacy) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', SeniorCitizen::class);

        $query = SeniorCitizen::active()
            ->with(['latestMlResult'])
            ->when($request->search, fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'like', "%{$request->search}%")
                ->orWhere('last_name', 'like', "%{$request->search}%")
                ->orWhere('osca_id', 'like', "%{$request->search}%")
                ->orWhere('official_osca_id', 'like', "%{$request->search}%")
            ))
            ->when($request->barangay, fn ($q) => $q->where('barangay', $request->barangay))
            ->when($request->risk, fn ($q) => $q->byRiskLevel($request->risk))
            ->when($request->cluster, fn ($q) => $q->whereHas('latestMlResult', fn ($m) => $m->where('cluster_named_id', (int) $request->cluster)
            ))
            ->latest();

        $seniors = $query->paginate(20)->withQueryString();
        $barangays = SeniorCitizen::barangayList();

        // Restrict stats to active (non-archived) seniors only. whereHas (EXISTS)
        // instead of materializing every active senior id into a PHP collection and
        // binding it as a giant IN(...) list — measured cost at 10k records.
        $latestActiveMlIds = MlResult::select(DB::raw('MAX(id)'))
            ->whereHas('seniorCitizen', fn ($q) => $q->active())
            ->groupBy('senior_citizen_id');

        $stats = [
            'total' => SeniorCitizen::active()->count(),
            'urgent' => MlResult::where('priority_flag', 'urgent')
                ->whereIn('id', $latestActiveMlIds)
                ->count(),
            'high' => MlResult::where('overall_risk_level', 'HIGH')
                ->whereIn('id', $latestActiveMlIds)
                ->count(),
        ];

        return view('seniors.index', compact('seniors', 'barangays', 'stats'));
    }

    /**
     * Deceased roster — a lifecycle status (SeniorCitizen::deceased()), not the
     * soft-delete archive. Records here stay in the normal table, fully
     * queryable; they're just excluded from the active roster's index() above.
     */
    public function deceasedIndex(Request $request)
    {
        $this->authorize('viewAny', SeniorCitizen::class);

        $query = SeniorCitizen::deceased()
            ->when($request->search, fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'like', "%{$request->search}%")
                ->orWhere('last_name', 'like', "%{$request->search}%")
                ->orWhere('osca_id', 'like', "%{$request->search}%")
                ->orWhere('official_osca_id', 'like', "%{$request->search}%")
            ))
            ->when($request->barangay, fn ($q) => $q->where('barangay', $request->barangay))
            ->orderByDesc('date_of_death')
            ->orderByDesc('status_changed_at');

        $seniors = $query->paginate(20)->withQueryString();
        $barangays = SeniorCitizen::barangayList();
        $total = SeniorCitizen::deceased()->count();

        return view('seniors.deceased', compact('seniors', 'barangays', 'total'));
    }

    public function create()
    {
        return view('seniors.create');
    }

    /**
     * In-progress "New Profile" drafts — senior_citizen_id is always null here
     * (a draft tied to an existing senior is just an unsaved edit buffer for
     * that already-active record, not a pending registration; it stays out of
     * this list). Visible to every admin/encoder, not just the drafter — a
     * colleague should be able to pick up someone else's unfinished entry.
     */
    public function draftsIndex(Request $request)
    {
        $this->authorize('viewAny', SeniorCitizen::class);

        $drafts = ProfileDraft::whereNull('senior_citizen_id')
            ->with('createdBy')
            ->when($request->search, function ($q, $term) {
                $term = strtolower($term);
                $firstName = DbHelper::jsonTextExpr('data', 'firstName');
                $lastName = DbHelper::jsonTextExpr('data', 'lastName');
                $q->where(function ($q) use ($term, $firstName, $lastName) {
                    $q->whereRaw("LOWER({$firstName}) LIKE ?", ["%{$term}%"])
                        ->orWhereRaw("LOWER({$lastName}) LIKE ?", ["%{$term}%"]);
                });
            })
            ->latest('updated_at')
            ->paginate(20)->withQueryString();

        return view('seniors.drafts.index', compact('drafts'));
    }

    /**
     * Hard delete — drafts are disposable scratch data (no SoftDeletes on the
     * model), unlike an actual senior record. The senior_citizen_id guard is
     * defense in depth: this action should only ever reach a new-profile draft.
     */
    public function draftsDestroy(ProfileDraft $draft)
    {
        $this->authorize('create', SeniorCitizen::class);
        abort_if($draft->senior_citizen_id !== null, 403);

        $draft->delete();

        return redirect()->route('seniors.drafts.index')->with('success', 'Draft deleted.');
    }

    public function store(Request $request)
    {
        return redirect()->route('seniors.index');
    }

    public function show(SeniorCitizen $senior)
    {
        $this->authorize('view', $senior);

        ActivityLog::record('viewed', $senior, "Senior profile viewed: {$senior->full_name}");

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
     *
     * Coordinate privacy: viewer role never sees the senior's real stored
     * coordinate, even when a verified GPS pin exists — see
     * effectiveLocationDisplay(). The effective coordinate is resolved ONCE,
     * before any facility ranking happens, and that single value drives BOTH
     * the facility distance calculations below AND the final `location`
     * display value. Facility coordinates are public knowledge (hospitals,
     * barangay halls, etc.), so computing distances against the real senior
     * position while only fuzzing the displayed pin would let a viewer
     * trilaterate the real location back out from 3+ facility distances —
     * this method structurally can't do that because there is only one
     * coordinate in scope past the top of the method.
     */
    private function locationPanel(SeniorCitizen $senior): array
    {
        $metric = $senior->latestAccessibilityMetric;
        $fullPrecision = (bool) auth()->user()?->hasAnyRole(['admin', 'encoder']);
        $loc = $this->effectiveLocationDisplay($senior, $fullPrecision);
        $seniorLat = $loc['lat'];
        $seniorLng = $loc['lng'];

        $facilities = [];

        if ($seniorLat !== null && $seniorLng !== null) {
            // Cached road routes (senior_facility_route_distances) were computed
            // from the senior's REAL stored coordinates. A viewer only ever sees
            // a generalized point here, so:
            //   (a) a cached route can never legitimately be "fresh" for it — and
            //       even a coincidental match would leak a road-network distance
            //       computed from the true pin, which is strictly more precise
            //       positional information than the generalized straight-line
            //       distance this panel intends to show;
            //   (b) the row must not offer the client-side lazy-route-upgrade
            //       hook either, since that hook POSTs `data-origin-lat/lng` to
            //       /api/gis/route-distance and the response is persisted keyed
            //       only by (senior_id, facility_id) — upgrading from a
            //       generalized origin would silently overwrite the real cached
            //       route admin/encoder rely on.
            // So viewer always gets straight-line-only distances with no route
            // lookup, computed locally, zero live calls either way.
            $routeByFacility = $fullPrecision
                ? $senior->facilityRouteDistances->keyBy('facility_id')
                : collect();

            // The nearest facility of each senior-relevant type (one per type),
            // ordered by distance — mirrors the GIS map popup so both surfaces
            // draw from the same set. Haversine is computed locally over the
            // Facility table, so the profile still makes zero live routing calls.
            $ranked = Facility::cachedActiveWithCoordinates()
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
                $route = $fullPrecision ? $routeByFacility->get($facility->id) : null;
                $routeFresh = $fullPrecision && $route
                    && $this->coordinatesMatch($route->origin_latitude, $seniorLat)
                    && $this->coordinatesMatch($route->origin_longitude, $seniorLng)
                    && $this->coordinatesMatch($route->destination_latitude, $facilityLat)
                    && $this->coordinatesMatch($route->destination_longitude, $facilityLng);

                $facilities[] = [
                    // Withheld (null) for viewer so the view's $needsRoute check
                    // never fires — see the docblock note above.
                    'facility_id' => $fullPrecision ? $facility->id : null,
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
            'location' => $loc,
            'facilities' => $facilities,
            'percent' => $percent,
            'status' => $band['label'] ?? null,
            'band' => $band,
        ];
    }

    /**
     * The one coordinate `locationPanel()` is allowed to use, resolved before
     * any facility work happens. Admin/encoder: unchanged — `SeniorCitizen::
     * locationDisplay()`'s existing status/label semantics ('none' / 'verified'
     * / 'approximate'), auth-unaware, exactly as before this task.
     *
     * Viewer: when there genuinely is no stored coordinate, 'none' is not
     * sensitive (there's nothing to hide), so it passes through unchanged too.
     * Otherwise viewer ALWAYS gets the deterministic barangay-generalized point
     * via CoordinatePrivacy — even for a senior with a real, field-verified GPS
     * pin — tagged with a distinct 'generalized_privacy' status/label so the
     * panel can honestly say "this is generalized because of your role" rather
     * than silently reusing the 'approximate' (data-quality) wording, which
     * would misleadingly claim "no GPS pin on record" when one actually exists.
     */
    private function effectiveLocationDisplay(SeniorCitizen $senior, bool $fullPrecision): array
    {
        $display = $senior->locationDisplay();

        if ($fullPrecision || $display['status'] === 'none') {
            return $display;
        }

        [$lat, $lng] = $this->coordinatePrivacy->resolve($senior, false);

        return [
            'status' => 'generalized_privacy',
            'lat' => $lat,
            'lng' => $lng,
            'label' => 'Generalized for your role — exact coordinates are restricted.',
            'source' => $senior->location_source,
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
                ->orWhere('official_osca_id', 'like', "%{$request->search}%")
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

        ActivityLog::record('exported', $senior, "Senior profile PDF exported: {$senior->full_name}");

        $senior->load(['latestMlResult.recommendations', 'latestQolSurvey']);
        $pdf = Pdf::loadView('seniors.pdf', compact('senior'))
            ->setPaper('a4', 'portrait');

        return $pdf->download("osca-profile-{$senior->osca_id}.pdf");
    }
}
