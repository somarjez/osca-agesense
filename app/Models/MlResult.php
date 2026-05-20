<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MlResult extends Model
{
    protected $fillable = [
        'senior_citizen_id', 'qol_survey_id', 'model_version',
        'prediction_source', 'is_cached_prediction', 'critical_flag',
        'is_stale', 'stale_reason', 'stale_at',
        'cluster_id', 'cluster_named_id', 'cluster_name',
        'ic_risk', 'env_risk', 'func_risk', 'composite_risk', 'wellbeing_score',
        'ic_risk_level', 'env_risk_level', 'func_risk_level', 'overall_risk_level',
        'priority_flag',
        // Rule-based domain risks
        'risk_medical', 'risk_financial', 'risk_social', 'risk_functional',
        'risk_housing', 'risk_hc_access', 'risk_sensory', 'rule_composite',
        // WHO domain scores
        'ic_score', 'env_score', 'func_score', 'qol_score',
        'section_scores', 'raw_output', 'processed_at', 'scored_at',
    ];

    protected $casts = [
        'section_scores'      => 'array',
        'raw_output'          => 'array',
        'processed_at'        => 'datetime',
        'scored_at'           => 'datetime',
        'is_cached_prediction' => 'boolean',
        'critical_flag'        => 'boolean',
        'is_stale'             => 'boolean',
        'stale_at'             => 'datetime',
        'ic_risk'          => 'float',
        'env_risk'         => 'float',
        'func_risk'        => 'float',
        'composite_risk'   => 'float',
        'wellbeing_score'  => 'float',
        'risk_medical'     => 'float',
        'risk_financial'   => 'float',
        'risk_social'      => 'float',
        'risk_functional'  => 'float',
        'risk_housing'     => 'float',
        'risk_hc_access'   => 'float',
        'risk_sensory'     => 'float',
        'rule_composite'   => 'float',
        'ic_score'         => 'float',
        'env_score'        => 'float',
        'func_score'       => 'float',
        'qol_score'        => 'float',
    ];

    public function seniorCitizen(): BelongsTo
    {
        return $this->belongsTo(SeniorCitizen::class);
    }

    public function qolSurvey(): BelongsTo
    {
        return $this->belongsTo(QolSurvey::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class, 'ml_result_id');
    }

    public function getRiskBadgeColorAttribute(): string
    {
        return match ($this->overall_risk_level) {
            'HIGH' => 'orange',
            'MODERATE' => 'yellow',
            'LOW' => 'green',
            default => 'gray',
        };
    }

    public function getPriorityLabelAttribute(): string
    {
        return match ($this->priority_flag) {
            'urgent'             => 'Urgent attention recommended',
            'priority_action'    => 'Priority action required',
            'planned_monitoring' => 'Planned monitoring',
            'maintenance'        => 'Routine maintenance',
            default              => '',
        };
    }

    public function isUrgentPriority(): bool
    {
        return $this->priority_flag === 'urgent';
    }

    /**
     * Reasons that invalidate data-derived cached results, including notebook_cache.
     * Any change to the senior's actual input data means the stored result no longer
     * reflects their current situation, regardless of how it was originally produced.
     */
    private const DATA_CHANGE_REASONS = [
        'senior_profile_updated',
        'qol_updated',
    ];

    /**
     * Mark this result as stale without deleting it.
     * The stale result is preserved and displayed until the next analysis run.
     *
     * notebook_cache rows CAN be marked stale when the senior's actual input data
     * changes (senior_profile_updated, qol_updated) — the stored result no longer
     * reflects the senior's current situation and must be recomputed.
     *
     * notebook_cache rows are NOT marked stale for model-version-only invalidation —
     * that protection is handled in findReusableResult() and is defense/pilot-specific.
     */
    public function markStale(string $reason): void
    {
        // notebook_cache rows are protected from version-only invalidation.
        // They can still be marked stale when real input data changes.
        if ($this->prediction_source === 'notebook_cache'
            && !in_array($reason, self::DATA_CHANGE_REASONS, true)) {
            return;
        }

        $this->update([
            'is_stale'     => true,
            'stale_reason' => $reason,
            'stale_at'     => now(),
        ]);
    }

    public function isStale(): bool
    {
        return (bool) $this->is_stale;
    }

    public function getClusterColorAttribute(): string
    {
        return match ($this->cluster_named_id) {
            1 => 'emerald',
            2 => 'amber',
            3 => 'rose',
            default => 'gray',
        };
    }

    /**
     * Human-readable label for the prediction source — used in UI badges.
     */
    public function getPredictionSourceLabelAttribute(): string
    {
        return match ($this->prediction_source) {
            'notebook_cache' => 'Notebook-Validated Cache',
            'live_model'     => 'Live ML Model',
            'fallback'       => 'Heuristic Fallback',
            default          => 'Unknown',
        };
    }

    /**
     * Tailwind color classes for the prediction source badge.
     */
    public function getPredictionSourceColorAttribute(): string
    {
        return match ($this->prediction_source) {
            'notebook_cache' => 'text-forest-700 bg-forest-50 border border-forest-200',
            'live_model'     => 'text-info-700 bg-info-50 border border-info-200',
            'fallback'       => 'text-ink-600 bg-paper-2 border border-paper-rule',
            default          => 'text-ink-500 bg-paper-2',
        };
    }
}
