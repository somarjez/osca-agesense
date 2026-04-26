<?php

namespace App\Http\Controllers;

use App\Models\MlResult;
use App\Models\SeniorCitizen;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class SeniorCitizenController extends Controller
{
    public function index(Request $request)
    {
        $latestMlIds = MlResult::select(DB::raw('MAX(id)'))->groupBy('senior_citizen_id');

        $query = SeniorCitizen::active()
            ->with(['latestMlResult'])
            ->when($request->search, fn($q) =>
                $q->where(fn($q) =>
                    $q->where('first_name', 'like', "%{$request->search}%")
                      ->orWhere('last_name', 'like', "%{$request->search}%")
                      ->orWhere('osca_id', 'like', "%{$request->search}%")
                ))
            ->when($request->barangay, fn($q) => $q->where('barangay', $request->barangay))
            ->when($request->risk, fn($q) => $q->byRiskLevel($request->risk))
            ->when($request->cluster, fn($q) => $q->whereHas('latestMlResult', fn($m) =>
                $m->where('cluster_named_id', (int) $request->cluster)
            ))
            ->latest();

        $seniors    = $query->paginate(20)->withQueryString();
        $barangays  = SeniorCitizen::barangayList();
        $stats = [
            'total' => SeniorCitizen::active()->count(),
            'critical' => MlResult::where('overall_risk_level', 'CRITICAL')
                ->whereIn('id', $latestMlIds)
                ->count(),
            'high' => MlResult::where('overall_risk_level', 'HIGH')
                ->whereIn('id', $latestMlIds)
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
            'qolSurveys'              => fn($q) => $q->latest()->limit(5),
            'latestMlResult.recommendations',
            'mlResults'               => fn($q) => $q->latest()->limit(3),
        ]);

        return view('seniors.show', compact('senior'));
    }

    public function edit(SeniorCitizen $senior)
    {
        return view('seniors.edit', compact('senior'));
    }

    public function update(Request $request, SeniorCitizen $senior)
    {
        return redirect()->route('seniors.show', $senior);
    }

    public function destroy(SeniorCitizen $senior)
    {
        $senior->delete();
        return redirect()->route('seniors.index')->with('success', 'Senior record archived.');
    }

    public function archives(Request $request)
    {
        $seniors = SeniorCitizen::onlyTrashed()
            ->when($request->search, fn($q) =>
                $q->where(fn($q) =>
                    $q->where('first_name', 'like', "%{$request->search}%")
                      ->orWhere('last_name',  'like', "%{$request->search}%")
                      ->orWhere('osca_id',    'like', "%{$request->search}%")
                ))
            ->when($request->barangay, fn($q) => $q->where('barangay', $request->barangay))
            ->latest('deleted_at')
            ->paginate(20)->withQueryString();

        $barangays = SeniorCitizen::barangayList();

        return view('seniors.archives', compact('seniors', 'barangays'));
    }

    public function restore(int $id)
    {
        SeniorCitizen::withTrashed()->findOrFail($id)->restore();
        return redirect()->route('seniors.archives')->with('success', 'Senior record restored to active.');
    }

    public function forceDestroy(int $id)
    {
        $senior = SeniorCitizen::withTrashed()->findOrFail($id);

        foreach ($senior->qolSurveys()->get() as $survey) {
            if ($survey->mlResult) {
                $survey->mlResult->recommendations()->delete();
                $survey->mlResult->delete();
            }
            $survey->delete();
        }

        $senior->mlResults()->delete();
        $senior->forceDelete();

        return redirect()->route('seniors.archives')->with('success', 'Senior record and all related data permanently deleted.');
    }

    public function export(SeniorCitizen $senior)
    {
        $senior->load(['latestMlResult.recommendations', 'latestQolSurvey']);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('seniors.pdf', compact('senior'))
            ->setPaper('a4', 'portrait');
        return $pdf->download("osca-profile-{$senior->osca_id}.pdf");
    }
}
