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
            'total'  => SeniorCitizen::active()->count(),
            'urgent' => MlResult::where('priority_flag', 'urgent')
                ->whereIn('id', $latestMlIds)
                ->count(),
            'high'   => MlResult::where('overall_risk_level', 'HIGH')
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
        // Soft-delete all QoL surveys so the QoL index doesn't show them as orphans
        $senior->qolSurveys()->each(fn($s) => $s->delete());
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

        $archivedSurveys = \App\Models\QolSurvey::onlyTrashed()
            ->with(['seniorCitizen' => fn($q) => $q->withTrashed()])
            ->when($request->search, fn($q) =>
                $q->whereHas('seniorCitizen', fn($q) => $q->withTrashed()->where(fn($q) =>
                    $q->where('first_name', 'like', "%{$request->search}%")
                      ->orWhere('last_name',  'like', "%{$request->search}%")
                )))
            ->when($request->barangay, fn($q) =>
                $q->whereHas('seniorCitizen', fn($q) => $q->withTrashed()->where('barangay', $request->barangay))
            )
            ->latest('deleted_at')
            ->paginate(20, ['*'], 'qol_page')->withQueryString();

        $barangays = SeniorCitizen::barangayList();

        return view('seniors.archives', compact('seniors', 'archivedSurveys', 'barangays'));
    }

    public function restore(int $id)
    {
        $senior = SeniorCitizen::withTrashed()->findOrFail($id);
        // Restore QoL surveys that were soft-deleted when this senior was archived
        \App\Models\QolSurvey::onlyTrashed()
            ->where('senior_citizen_id', $senior->id)
            ->each(fn($s) => $s->restore());
        $senior->restore();
        return redirect()->route('seniors.archives')->with('success', 'Senior record restored to active.');
    }

    public function forceDestroy(int $id)
    {
        $senior = SeniorCitizen::withTrashed()->findOrFail($id);

        foreach ($senior->qolSurveys()->withTrashed()->get() as $survey) {
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
