<?php

namespace App\Livewire\Surveys;

use App\Jobs\RunMlPipeline;
use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use Livewire\Component;

class QolSurveyForm extends Component
{
    public SeniorCitizen $senior;

    public ?QolSurvey $survey = null;

    public int $step = 1;

    public int $totalSteps = 8;

    public bool $showConfirm = false;

    public bool $isProcessing = false;

    // ── Section A ─────────────────────────────────────────────────────────────
    public ?int $a1 = null;

    public ?int $a2 = null;

    public ?int $a3 = null;

    public ?int $a4 = null;

    // ── Section B ─────────────────────────────────────────────────────────────
    public ?int $b1 = null;

    public ?int $b2 = null;

    public ?int $b3 = null;

    public ?int $b4 = null;

    public ?int $b5 = null;

    // ── Section C ─────────────────────────────────────────────────────────────
    public ?int $c1 = null;

    public ?int $c2 = null;

    public ?int $c3 = null;

    public ?int $c4 = null;

    // ── Section D ─────────────────────────────────────────────────────────────
    public ?int $d1 = null;

    public ?int $d2 = null;

    public ?int $d3 = null;

    public ?int $d4 = null;

    // ── Section E ─────────────────────────────────────────────────────────────
    public ?int $e1 = null;

    public ?int $e2 = null;

    public ?int $e3 = null;

    public ?int $e4 = null;

    public ?int $e5 = null;

    // ── Section F ─────────────────────────────────────────────────────────────
    public ?int $f1 = null;

    public ?int $f2 = null;

    public ?int $f3 = null;

    public ?int $f4 = null;

    // ── Section G ─────────────────────────────────────────────────────────────
    public ?int $g1 = null;

    public ?int $g2 = null;

    public ?int $g3 = null;

    // ── Section H (optional) ──────────────────────────────────────────────────
    public ?int $h1 = null;

    public ?int $h2 = null;

    public string $surveyDate = '';

    public function mount(int $seniorId, ?int $surveyId = null): void
    {
        $this->senior = SeniorCitizen::findOrFail($seniorId);
        $this->authorize('update', $this->senior);
        $this->surveyDate = now()->format('Y-m-d');

        if ($surveyId) {
            $this->survey = QolSurvey::withTrashed()->findOrFail($surveyId);
            $this->populateFromSurvey($this->survey);
            $this->step = $this->survey->step;
        }
    }

    public function nextStep(): void
    {
        $this->validateSection();
        if ($this->step < $this->totalSteps) {
            $this->step++;
            $this->dispatch('qol-step-changed');
        }
    }

    private function validateSection(): void
    {
        // Section H (step 8) is intentionally skippable, so it can't use the
        // uniform "required" rule the other sections share — validate it
        // separately as optional-but-bounded instead.
        if ($this->step === 8) {
            $this->validate([
                'h1' => 'nullable|integer|min:1|max:5',
                'h2' => 'nullable|integer|min:1|max:5',
            ]);

            return;
        }

        $required = match ($this->step) {
            1 => ['a1' => $this->a1, 'a2' => $this->a2, 'a3' => $this->a3, 'a4' => $this->a4],
            2 => ['b1' => $this->b1, 'b2' => $this->b2, 'b3' => $this->b3, 'b4' => $this->b4, 'b5' => $this->b5],
            3 => ['c1' => $this->c1, 'c2' => $this->c2, 'c3' => $this->c3, 'c4' => $this->c4],
            4 => ['d1' => $this->d1, 'd2' => $this->d2, 'd3' => $this->d3, 'd4' => $this->d4],
            5 => ['e1' => $this->e1, 'e2' => $this->e2, 'e3' => $this->e3, 'e4' => $this->e4, 'e5' => $this->e5],
            6 => ['f1' => $this->f1, 'f2' => $this->f2, 'f3' => $this->f3, 'f4' => $this->f4],
            7 => ['g1' => $this->g1, 'g2' => $this->g2, 'g3' => $this->g3],
            default => [],
        };

        $rules = array_fill_keys(array_keys($required), 'required|integer|min:1|max:5');
        if ($rules) {
            $this->validate($rules, array_fill_keys(
                array_map(fn ($k) => "{$k}.required", array_keys($rules)),
                'Please answer all questions in this section before continuing.'
            ));
        }
    }

    public function prevStep(): void
    {
        if ($this->step > 1) {
            $this->step--;
            $this->dispatch('qol-step-changed');
        }
    }

    public function goToStep(int $step): void
    {
        $this->step = max(1, min($step, $this->totalSteps));
        $this->dispatch('qol-step-changed');
    }

    public function confirmSubmit(): void
    {
        $this->showConfirm = true;
    }

    public function submitSurvey(): void
    {
        // Livewire network calls bypass HTTP route middleware, so enforce policy here
        // (single source of truth: SeniorCitizenPolicy, same role gate as before).
        $this->authorize('update', $this->senior);

        $this->isProcessing = true;

        $data = [
            'senior_citizen_id' => $this->senior->id,
            'survey_date' => $this->surveyDate,
            'survey_version' => 'v1',
            'a1_enjoy_life' => $this->a1,
            'a2_life_satisfaction' => $this->a2,
            'a3_future_outlook' => $this->a3,
            'a4_meaningfulness' => $this->a4,
            'b1_physical_energy' => $this->b1,
            'b2_pain_discomfort' => $this->b2,
            'b3_health_self_care' => $this->b3,
            'b4_health_outside' => $this->b4,
            'b5_mobility' => $this->b5,
            'c1_happiness' => $this->c1,
            'c2_calm_peace' => $this->c2,
            'c3_loneliness' => $this->c3,
            'c4_confidence' => $this->c4,
            'd1_independence' => $this->d1,
            'd2_time_control' => $this->d2,
            'd3_life_control' => $this->d3,
            'd4_income_limits' => $this->d4,
            'e1_social_support' => $this->e1,
            'e2_close_person' => $this->e2,
            'e3_community_opportunities' => $this->e3,
            'e4_participation' => $this->e4,
            'e5_respect' => $this->e5,
            'f1_home_safety' => $this->f1,
            'f2_neighborhood_safety' => $this->f2,
            'f3_service_access' => $this->f3,
            'f4_home_comfort' => $this->f4,
            'g1_household_expenses' => $this->g1,
            'g2_medical_afford' => $this->g2,
            'g3_personal_wants' => $this->g3,
            'h1_belief_comfort' => $this->h1,
            'h2_belief_practice' => $this->h2,
            'status' => 'submitted',
        ];

        if ($this->survey) {
            $this->survey->update($data);
        } else {
            $this->survey = QolSurvey::create($data);
        }

        // Compute domain scores
        $this->survey->computeScores();

        // Dispatch ML pipeline as a background job to avoid HTTP timeout
        RunMlPipeline::dispatch($this->senior->id, $this->survey->id);
        session()->flash('success', 'QoL Survey submitted. ML analysis is running in the background.');

        // Leave isProcessing true (and showConfirm never reset) so the modal/overlay
        // stay on-screen for the gap between this response and the browser actually
        // navigating away — resetting them here exposed the enabled submit button
        // underneath for a moment before the redirect took effect.
        $this->redirect(route('seniors.show', $this->senior));
    }

    public function saveDraft(): void
    {
        // Livewire network calls bypass HTTP route middleware, so enforce policy here
        // (single source of truth: SeniorCitizenPolicy, same role gate as before).
        $this->authorize('update', $this->senior);

        $this->survey = QolSurvey::updateOrCreate(
            ['senior_citizen_id' => $this->senior->id, 'status' => 'draft'],
            array_merge($this->currentData(), ['status' => 'draft', 'step' => $this->step])
        );
        session()->flash('success', 'Draft saved.');
        $this->redirect(route('surveys.qol.create', $this->senior));
    }

    private function currentData(): array
    {
        return [
            'senior_citizen_id' => $this->senior->id,
            'survey_date' => $this->surveyDate,
            'a1_enjoy_life' => $this->a1, 'a2_life_satisfaction' => $this->a2,
            'a3_future_outlook' => $this->a3, 'a4_meaningfulness' => $this->a4,
            'b1_physical_energy' => $this->b1, 'b2_pain_discomfort' => $this->b2,
            'b3_health_self_care' => $this->b3, 'b4_health_outside' => $this->b4,
            'b5_mobility' => $this->b5,
            'c1_happiness' => $this->c1, 'c2_calm_peace' => $this->c2,
            'c3_loneliness' => $this->c3, 'c4_confidence' => $this->c4,
            'd1_independence' => $this->d1, 'd2_time_control' => $this->d2,
            'd3_life_control' => $this->d3, 'd4_income_limits' => $this->d4,
            'e1_social_support' => $this->e1, 'e2_close_person' => $this->e2,
            'e3_community_opportunities' => $this->e3, 'e4_participation' => $this->e4,
            'e5_respect' => $this->e5,
            'f1_home_safety' => $this->f1, 'f2_neighborhood_safety' => $this->f2,
            'f3_service_access' => $this->f3, 'f4_home_comfort' => $this->f4,
            'g1_household_expenses' => $this->g1, 'g2_medical_afford' => $this->g2,
            'g3_personal_wants' => $this->g3,
            'h1_belief_comfort' => $this->h1, 'h2_belief_practice' => $this->h2,
        ];
    }

    private function populateFromSurvey(QolSurvey $s): void
    {
        $this->a1 = $s->a1_enjoy_life;
        $this->a2 = $s->a2_life_satisfaction;
        $this->a3 = $s->a3_future_outlook;
        $this->a4 = $s->a4_meaningfulness;
        $this->b1 = $s->b1_physical_energy;
        $this->b2 = $s->b2_pain_discomfort;
        $this->b3 = $s->b3_health_self_care;
        $this->b4 = $s->b4_health_outside;
        $this->b5 = $s->b5_mobility;
        $this->c1 = $s->c1_happiness;
        $this->c2 = $s->c2_calm_peace;
        $this->c3 = $s->c3_loneliness;
        $this->c4 = $s->c4_confidence;
        $this->d1 = $s->d1_independence;
        $this->d2 = $s->d2_time_control;
        $this->d3 = $s->d3_life_control;
        $this->d4 = $s->d4_income_limits;
        $this->e1 = $s->e1_social_support;
        $this->e2 = $s->e2_close_person;
        $this->e3 = $s->e3_community_opportunities;
        $this->e4 = $s->e4_participation;
        $this->e5 = $s->e5_respect;
        $this->f1 = $s->f1_home_safety;
        $this->f2 = $s->f2_neighborhood_safety;
        $this->f3 = $s->f3_service_access;
        $this->f4 = $s->f4_home_comfort;
        $this->g1 = $s->g1_household_expenses;
        $this->g2 = $s->g2_medical_afford;
        $this->g3 = $s->g3_personal_wants;
        $this->h1 = $s->h1_belief_comfort;
        $this->h2 = $s->h2_belief_practice;
        $this->surveyDate = $s->survey_date?->format('Y-m-d') ?? now()->format('Y-m-d');
    }

    public function getSectionProgress(): array
    {
        return [
            1 => array_filter([$this->a1, $this->a2, $this->a3, $this->a4]),
            2 => array_filter([$this->b1, $this->b2, $this->b3, $this->b4, $this->b5]),
            3 => array_filter([$this->c1, $this->c2, $this->c3, $this->c4]),
            4 => array_filter([$this->d1, $this->d2, $this->d3, $this->d4]),
            5 => array_filter([$this->e1, $this->e2, $this->e3, $this->e4, $this->e5]),
            6 => array_filter([$this->f1, $this->f2, $this->f3, $this->f4]),
            7 => array_filter([$this->g1, $this->g2, $this->g3]),
            8 => array_filter([$this->h1, $this->h2]),
        ];
    }

    /**
     * Honest completion signal for the survey-guide rail: answered items across
     * sections A-G (29 required ratings) — Section H is intentionally optional
     * (see validateSection()) and excluded from the denominator.
     */
    public function completionPercent(): int
    {
        $progress = $this->getSectionProgress();
        $totalRequired = 29; // a1-g3: 4+5+4+4+5+4+3
        $answered = 0;
        for ($s = 1; $s <= 7; $s++) {
            $answered += count($progress[$s]);
        }

        return (int) round(($answered / $totalRequired) * 100);
    }

    public function stepStatusText(): string
    {
        $percent = $this->completionPercent();

        return match (true) {
            $percent === 0 => "Let's get started.",
            $percent < 100 => 'In progress.',
            default => 'Ready to submit.',
        };
    }

    public function render()
    {
        return view('livewire.surveys.qol-survey-form');
    }
}
