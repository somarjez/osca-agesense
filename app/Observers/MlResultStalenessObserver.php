<?php

namespace App\Observers;

use App\Models\MlResult;
use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks the latest ml_results row for a senior as stale when model-relevant
 * profile or QoL data changes.
 *
 * Stale results are NOT deleted — they remain visible in the UI with a stale
 * indicator until the next analysis run overwrites them.
 *
 * notebook_cache rows are never marked stale — they are permanently validated
 * from the notebook and can only be changed via ml:repair-notebook-cache.
 */
class MlResultStalenessObserver
{
    /**
     * Fields on SeniorCitizen that feed directly into ML preprocessing.
     * Changes to any of these invalidate the cached risk estimate.
     *
     * Non-analytical fields (contact_number, religion, philsys_id, latitude,
     * longitude, osca_id, consent_given_at, encoded_by, status, etc.) are
     * intentionally excluded — they do not affect scoring.
     */
    private const SENIOR_ML_FIELDS = [
        'date_of_birth',
        'gender',
        'marital_status',
        'educational_attainment',
        'monthly_income_range',
        'num_children',
        'num_working_children',
        'child_financial_support',
        'spouse_working',
        'household_size',
        'income_source',
        'real_assets',
        'movable_assets',
        'living_with',
        'household_condition',
        'community_service',
        'specialization',
        'medical_concern',
        'dental_concern',
        'optical_concern',
        'hearing_concern',
        'social_emotional_concern',
        'healthcare_difficulty',
        'has_medical_checkup',
        'checkup_schedule',
    ];

    // ── SeniorCitizen events ─────────────────────────────────────────────────

    public function updated(Model $model): void
    {
        if ($model instanceof SeniorCitizen) {
            $this->handleSeniorUpdated($model);
        } elseif ($model instanceof QolSurvey) {
            $this->handleQolUpdated($model);
        }
    }

    public function created(Model $model): void
    {
        if ($model instanceof QolSurvey) {
            // A new survey for an existing senior means the old result is stale.
            $this->markLatestStale($model->senior_citizen_id, 'qol_updated');
        }
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function handleSeniorUpdated(SeniorCitizen $senior): void
    {
        $dirty = $senior->getDirty();
        unset($dirty['updated_at']);

        $mlRelevantChanged = array_intersect(array_keys($dirty), self::SENIOR_ML_FIELDS);

        if (empty($mlRelevantChanged)) {
            return;
        }

        $this->markLatestStale($senior->id, 'senior_profile_updated');
    }

    private function handleQolUpdated(QolSurvey $survey): void
    {
        $dirty = $survey->getDirty();
        unset($dirty['updated_at']);

        // Skip noise-only saves (e.g. status field only)
        $nonStatusChanged = array_diff_key($dirty, array_flip(['status', 'updated_at']));

        if (empty($nonStatusChanged)) {
            return;
        }

        $this->markLatestStale($survey->senior_citizen_id, 'qol_updated');
    }

    private function markLatestStale(int $seniorId, string $reason): void
    {
        $latest = MlResult::where('senior_citizen_id', $seniorId)
            ->where('is_stale', false)
            ->orderByDesc('id')
            ->first();

        if ($latest) {
            $latest->markStale($reason);
        }
    }
}
