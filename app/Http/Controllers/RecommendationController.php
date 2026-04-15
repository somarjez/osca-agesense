<?php

namespace App\Http\Controllers;

use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    public function index(Request $request)
    {
        $urgencies = ['immediate', 'urgent', 'planned', 'maintenance'];
        $categories = Recommendation::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $recs = Recommendation::with(['seniorCitizen'])
            ->when($request->status,   fn($q) => $q->where('status', $request->status))
            ->when($request->urgency,  fn($q) => $q->where('urgency', $request->urgency))
            ->when($request->category, fn($q) => $q->byCategory($request->category))
            ->when($request->barangay, fn($q) =>
                $q->whereHas('seniorCitizen', fn($q) => $q->where('barangay', $request->barangay))
            )
            ->orderByRaw("
                CASE urgency
                    WHEN 'immediate' THEN 1
                    WHEN 'urgent' THEN 2
                    WHEN 'planned' THEN 3
                    WHEN 'maintenance' THEN 4
                    ELSE 5
                END
            ")
            ->orderBy('priority')
            ->paginate(30)->withQueryString();

        return view('recommendations.index', compact('recs', 'urgencies', 'categories'));
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
