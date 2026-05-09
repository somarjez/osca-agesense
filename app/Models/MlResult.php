<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MlResult extends Model
{
    protected $fillable = [
        'senior_citizen_id', 'qol_survey_id', 'model_version',
        'cluster_id', 'cluster_named_id', 'cluster_name',
        'ic_risk', 'env_risk', 'func_risk', 'composite_risk', 'wellbeing_score',
        'ic_risk_level', 'env_risk_level', 'func_risk_level', 'overall_risk_level',
        'priority_flag',
        // Rule-based domain risks
        'risk_medical', 'risk_financial', 'risk_social', 'risk_functional',
        'risk_housing', 'risk_hc_access', 'risk_sensory', 'rule_composite',
        // WHO domain scores
        'ic_score', 'env_score', 'func_score', 'qol_score',
        'section_scores', 'raw_output', 'processed_at',
    ];

    protected $casts = [
        'section_scores' => 'array',
        'raw_output'     => 'array',
        'processed_at'   => 'datetime',
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

    public function getClusterColorAttribute(): string
    {
        return match ($this->cluster_named_id) {
            1 => 'emerald',
            2 => 'amber',
            3 => 'rose',
            default => 'gray',
        };
    }
}
