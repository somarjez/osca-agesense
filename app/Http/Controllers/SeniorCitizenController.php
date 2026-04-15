<?php

namespace App\Http\Controllers;

use App\Models\MlResult;
use App\Models\SeniorCitizen;
use App\Services\MlService;
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

    public function restore(SeniorCitizen $senior)
    {
        SeniorCitizen::withTrashed()->findOrFail($senior->id)->restore();
        return back()->with('success', 'Senior record restored.');
    }

    public function runAnalysis(SeniorCitizen $senior)
    {
        $survey = $senior->latestQolSurvey;
        if (!$survey) {
            return back()->with('error', 'No QoL survey found. Please complete a survey first.');
        }
        try {
            $result = app(MlService::class)->runPipeline($senior, $survey);
            return back()->with('success', "ML analysis complete. Risk level: {$result->overall_risk_level}");
        } catch (\Exception $e) {
            return back()->with('error', 'ML analysis failed: ' . $e->getMessage());
        }
    }

    public function exportPdf(SeniorCitizen $senior)
    {
        $senior->load(['latestMlResult.recommendations', 'latestQolSurvey']);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('seniors.pdf', compact('senior'));
        return $pdf->download("osca-profile-{$senior->osca_id}.pdf");
    }
}
