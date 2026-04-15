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
        'section_scores', 'raw_output', 'processed_at',
    ];

    protected $casts = [
        'section_scores' => 'array',
        'raw_output' => 'array',
        'processed_at' => 'datetime',
        'ic_risk' => 'float',
        'env_risk' => 'float',
        'func_risk' => 'float',
        'composite_risk' => 'float',
        'wellbeing_score' => 'float',
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
            'CRITICAL' => 'red',
            'HIGH' => 'orange',
            'MODERATE' => 'yellow',
            'LOW' => 'green',
            default => 'gray',
        };
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
