<?php

namespace App\Http\Controllers;

use App\Models\ProfileDraft;
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

    /**
     * Resume a SPECIFIC new-profile draft by its own id (from the Drafts list),
     * as opposed to ProfileSurvey::mount()'s "latest draft for me" convenience
     * fallback used when visiting /seniors/create with no draft id at all —
     * this is what lets anyone continue any draft, not just their own latest.
     */
    public function profileDraftContinue(ProfileDraft $draft)
    {
        // Defensive only: this list/route should never see a draft tied to an
        // existing senior (that's an edit-in-progress buffer, not a pending
        // registration), but if it ever happens, route to the right place.
        if ($draft->senior_citizen_id) {
            $this->authorize('update', $draft->seniorCitizen);

            return redirect()->route('seniors.edit', $draft->seniorCitizen);
        }

        $this->authorize('create', SeniorCitizen::class);

        return view('seniors.create', ['draftId' => $draft->id]);
    }

    public function qolIndex(Request $request)
    {
        $surveys = QolSurvey::with(['seniorCitizen'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->barangay, fn ($q) => $q->whereHas('seniorCitizen', fn ($q) => $q->where('barangay', $request->barangay))
            )
            ->when($request->search, fn ($q, $term) => $q->whereHas('seniorCitizen', fn ($q) => $q->searchTerm($term)))
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

        // Cascade soft-delete: recommendations → ml_result → survey. Mirrors
        // SeniorCitizenController::destroy()'s archive cascade. Without this,
        // the ml_result kept pointing at a trashed survey — an "orphan" that
        // every "latest ml_result" reader still picked as current (see
        // App\Support\CurrentMlResult) — which is why re-running an
        // assessment after deleting the latest QoL survey used to silently
        // update a row nothing displayed. The confirm modal already tells
        // the user this survey's decision-support output will be deleted;
        // this makes that true.
        if ($survey->mlResult) {
            $survey->mlResult->recommendations()->delete();
            $survey->mlResult->delete();
        }

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
        $survey = QolSurvey::onlyTrashed()->findOrFail($id);
        // Defensive: this route restores an individually deleted survey (the
        // Archives page only lists ones with no archived_with_senior_at
        // marker — see SeniorCitizenController::archives()), but normalize
        // here too so a restored row can never carry a stale marker into
        // the senior's next archive/restore cycle. Assigned before restore()
        // so SoftDeletes::restore()'s own save() clears both columns in one
        // UPDATE — same pattern as SeniorCitizenController::restoreArchivedSurveys().
        $survey->archived_with_senior_at = null;
        $survey->restore();

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
