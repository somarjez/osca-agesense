<?php

namespace App\Http\Controllers;

use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    public function index(Request $request)
    {
        $stats = [
            'total'     => Recommendation::count(),
            'pending'   => Recommendation::where('status', 'pending')->count(),
            'immediate' => Recommendation::where('urgency', 'immediate')->where('status', 'pending')->count(),
            'seniors'   => SeniorCitizen::active()->whereHas('recommendations')->count(),
        ];

        $seniors = SeniorCitizen::active()
            ->whereHas('recommendations')
            ->withCount([
                'recommendations',
                'recommendations as pending_count'   => fn($q) => $q->where('status', 'pending'),
                'recommendations as immediate_count' => fn($q) => $q->whereIn('urgency', ['immediate', 'urgent'])->where('status', 'pending'),
            ])
            ->with(['latestMlResult'])
            ->when($request->barangay, fn($q) => $q->where('barangay', $request->barangay))
            ->when($request->risk,     fn($q) => $q->byRiskLevel($request->risk))
            ->when($request->has_urgent, fn($q) =>
                $q->whereHas('recommendations', fn($r) =>
                    $r->whereIn('urgency', ['immediate', 'urgent'])->where('status', 'pending')
                )
            )
            ->orderByDesc('immediate_count')
            ->orderByDesc('pending_count')
            ->paginate(20)
            ->withQueryString();

        $barangays = SeniorCitizen::barangayList();

        return view('recommendations.index', compact('seniors', 'barangays', 'stats'));
    }

    public function show(SeniorCitizen $senior)
    {
        $recommendations = $senior->recommendations()
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
