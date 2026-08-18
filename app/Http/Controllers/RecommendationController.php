<?php

namespace App\Http\Controllers;

use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use App\Support\CurrentMlResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecommendationController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', SeniorCitizen::class);

        // TC-PERF-05 (audit finding): the original $stats block ran FOUR
        // separate queries, three of them via Recommendation::current()'s
        // correlated MAX(id) subquery re-evaluated per matching row; the
        // $seniors query below ran three MORE such correlated subqueries per
        // row (via withCount()), sorted before pagination — together
        // measured at 8.7-9.5s against an 89,476-row table.
        //
        // Both are rebuilt on ONE shared derived table instead: "each
        // senior's current ml_result_id" (one row per senior, ~366 here),
        // computed once, then INNER JOINed onto recommendations by
        // ml_result_id — an indexed join (recommendations_ml_result_id_index),
        // never a correlated subquery. (A single correlated-subquery-in-a-
        // GROUP-BY version was tried first and measured ~2.6s — better, but
        // still ran the MAX(id) lookup once per recommendation row; this
        // join-based version measured ~1.6s for the seniors query alone.)
        //
        // CurrentMlResult::perSeniorSubquery() (not a bare "MAX(id) ... GROUP
        // BY") — this raw DB::table() query bypasses Eloquent's SoftDeletes
        // global scope entirely, so without its explicit deleted_at filter a
        // soft-deleted ml_results row still won the MAX(id) and got joined
        // here. Combined with the missing deleted_at filter on
        // `recommendations` below, that's what made a single re-run's
        // superseded-but-not-yet-purged recommendation rows double every
        // count on this page (20 shown on the profile, 40 here) — see
        // MlService::persistResults()'s soft-delete-then-insert.
        $currentMlResults = CurrentMlResult::perSeniorSubquery();

        $recCounts = DB::table('recommendations')
            ->whereNull('recommendations.deleted_at')
            ->joinSub($currentMlResults, 'current_ml', fn ($join) => $join->on('recommendations.ml_result_id', '=', 'current_ml.ml_result_id'))
            ->select('current_ml.senior_citizen_id')
            ->selectRaw('COUNT(*) as recommendations_count')
            ->selectRaw("SUM(CASE WHEN recommendations.status = 'pending' THEN 1 ELSE 0 END) as pending_count")
            ->selectRaw("SUM(CASE WHEN recommendations.status = 'pending' AND recommendations.urgency IN ('immediate', 'urgent') THEN 1 ELSE 0 END) as immediate_count")
            ->groupBy('current_ml.senior_citizen_id');

        $statsRow = DB::table('recommendations')
            ->whereNull('recommendations.deleted_at')
            ->joinSub($currentMlResults, 'current_ml', fn ($join) => $join->on('recommendations.ml_result_id', '=', 'current_ml.ml_result_id'))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN recommendations.status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN recommendations.urgency = 'immediate' AND recommendations.status = 'pending' THEN 1 ELSE 0 END) as immediate")
            ->first();

        $stats = [
            'total' => (int) ($statsRow->total ?? 0),
            'pending' => (int) ($statsRow->pending ?? 0),
            'immediate' => (int) ($statsRow->immediate ?? 0),
            // Was SeniorCitizen::active()->whereHas('currentRecommendations')
            // ->count() — an EXISTS check that still nested Recommendation::
            // scopeCurrent()'s correlated subquery inside it (measured ~1.1s
            // alone). Reusing $recCounts (already computed above, one row
            // per senior with a current rec) turns this into a single
            // indexed join + count, same as everything else on this page.
            'seniors' => DB::table('senior_citizens')
                ->joinSub($recCounts, 'rc', fn ($join) => $join->on('senior_citizens.id', '=', 'rc.senior_citizen_id'))
                ->where('senior_citizens.status', 'active')
                ->count(),
        ];

        $seniors = SeniorCitizen::active()
            ->joinSub($recCounts, 'rec_counts', fn ($join) => $join->on('senior_citizens.id', '=', 'rec_counts.senior_citizen_id'))
            ->addSelect(
                'senior_citizens.*',
                'rec_counts.recommendations_count',
                'rec_counts.pending_count',
                'rec_counts.immediate_count'
            )
            ->with(['latestMlResult'])
            ->when($request->barangay, fn ($q) => $q->where('barangay', $request->barangay))
            ->when($request->risk, fn ($q) => $q->byRiskLevel($request->risk))
            ->when($request->has_urgent, fn ($q) => $q->where('rec_counts.immediate_count', '>', 0))
            ->when($request->search, fn ($q, $term) => $q->searchTerm($term))
            ->when($request->quick === 'pending', fn ($q) => $q->where('rec_counts.pending_count', '>', 0))
            ->when($request->quick === 'done', fn ($q) => $q->where('rec_counts.pending_count', '=', 0))
            ->orderByDesc('rec_counts.immediate_count')
            ->orderByDesc('rec_counts.pending_count')
            ->paginate(20)
            ->withQueryString();

        $barangays = SeniorCitizen::barangayList();

        return view('recommendations.index', compact('seniors', 'barangays', 'stats'));
    }

    public function show(SeniorCitizen $senior)
    {
        $this->authorize('view', $senior);

        $recommendations = $senior->currentRecommendations()
            ->with('mlResult')
            ->orderBy('priority')
            ->get();

        return view('recommendations.show', compact('senior', 'recommendations'));
    }

    public function updateStatus(Request $request, Recommendation $rec)
    {
        $request->validate(['status' => 'required|in:pending,in_progress,completed,dismissed']);
        $rec->update(['status' => $request->status]);

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return back()->with('success', 'Status updated.');
    }

    public function assign(Request $request, Recommendation $rec)
    {
        $request->validate(['assigned_to' => 'nullable|exists:users,id']);
        $rec->update(['assigned_to' => $request->assigned_to]);

        return back()->with('success', 'Assigned.');
    }
}
