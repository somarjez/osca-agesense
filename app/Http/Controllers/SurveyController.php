<?php

namespace App\Http\Controllers;

use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use Illuminate\Http\Request;

class SurveyController extends Controller
{
    public function profileCreate(?int $senior = null)
    {
        $s = $senior ? SeniorCitizen::findOrFail($senior) : null;
        return view('seniors.create', compact('s'));
    }

    public function qolIndex(Request $request)
    {
        $surveys = QolSurvey::with(['seniorCitizen'])
            ->when($request->status,   fn($q) => $q->where('status', $request->status))
            ->when($request->barangay, fn($q) =>
                $q->whereHas('seniorCitizen', fn($q) => $q->where('barangay', $request->barangay))
            )
            ->latest('survey_date')
            ->paginate(20)->withQueryString();

        return view('surveys.qol.index', compact('surveys'));
    }

    public function qolCreate(SeniorCitizen $senior)
    {
        return view('surveys.qol.create', compact('senior'));
    }

    public function qolEdit(QolSurvey $survey)
    {
        $survey->load('seniorCitizen');
        return view('surveys.qol.create', ['senior' => $survey->seniorCitizen, 'surveyId' => $survey->id]);
    }

    public function qolResults(QolSurvey $survey)
    {
        $survey->load(['seniorCitizen', 'mlResult.recommendations']);
        return view('surveys.qol.results', compact('survey'));
    }
}
