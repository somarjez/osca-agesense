<?php

namespace App\Http\Controllers;

use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use Illuminate\Http\Request;

class SurveyController extends Controller
{
    public function profileCreate(?SeniorCitizen $senior = null)
    {
        $senior
            ? $this->authorize('update', $senior)
            : $this->authorize('create', SeniorCitizen::class);

        $s = $senior;

        return view('seniors.create', compact('s'));
    }

    public function qolIndex(Request $request)
    {
        $surveys = QolSurvey::with(['seniorCitizen'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->barangay, fn ($q) => $q->whereHas('seniorCitizen', fn ($q) => $q->where('barangay', $request->barangay))
            )
            ->when($request->search, fn ($q, $term) => $q->whereHas('seniorCitizen', fn ($q) => $q
                ->where('osca_id', 'like', "%{$term}%")
                ->orWhereRaw("LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?", ['%'.strtolower($term).'%'])
            ))
            ->latest('survey_date')
            ->paginate(20)->withQueryString();

        return view('surveys.qol.index', compact('surveys'));
    }

    public function qolCreate(SeniorCitizen $senior)
    {
        $this->authorize('update', $senior);

        $draft = $senior->qolSurveys()->where('status', 'draft')->latest()->first();

        return view('surveys.qol.create', [
            'senior' => $senior,
            'surveyId' => $draft?->id,
        ]);
    }

    public function qolEdit(QolSurvey $survey)
    {
        $survey->load('seniorCitizen');

        return view('surveys.qol.create', ['senior' => $survey->seniorCitizen, 'surveyId' => $survey->id]);
    }

    public function qolDestroy(QolSurvey $survey)
    {
        $senior = $survey->seniorCitizen;
        $survey->delete();

        if (request()->headers->get('referer') && str_contains(request()->headers->get('referer'), '/seniors/')) {
            return redirect()->route('seniors.show', $senior)
                ->with('success', 'QoL survey deleted.');
        }

        return redirect()->route('surveys.qol.index')
            ->with('success', 'QoL survey deleted.');
    }

    public function qolRestore(int $id)
    {
        QolSurvey::onlyTrashed()->findOrFail($id)->restore();

        return redirect()->route('seniors.archives')->with('success', 'QoL survey restored.');
    }

    public function qolResults(QolSurvey $survey)
    {
        $survey->load(['mlResult.recommendations']);
        // Include soft-deleted seniors so results remain readable after archiving
        $survey->setRelation(
            'seniorCitizen',
            SeniorCitizen::withTrashed()->find($survey->senior_citizen_id)
        );

        return view('surveys.qol.results', compact('survey'));
    }
}
