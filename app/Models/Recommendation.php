<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Recommendation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'ml_result_id', 'senior_citizen_id',
        'priority', 'type', 'domain', 'category',
        'action', 'urgency', 'risk_level',
        // v2 enriched fields (nullable — backward compat with old ML payloads)
        'recommendation_code', 'service_provider', 'evidence_source',
        'apa_reference', 'source_type',
        'eligibility_basis', 'documents_needed', 'requires_human_validation',
        // workflow fields
        'status', 'notes', 'target_date', 'assigned_to',
    ];

    protected $casts = [
        'target_date' => 'date',
        'documents_needed' => 'array',
        'requires_human_validation' => 'boolean',
    ];

    public function seniorCitizen(): BelongsTo
    {
        return $this->belongsTo(SeniorCitizen::class);
    }

    public function mlResult(): BelongsTo
    {
        return $this->belongsTo(MlResult::class);
    }

    public function getUrgencyBadgeAttribute(): string
    {
        return match ($this->urgency) {
            'immediate' => 'bg-red-100 text-red-800',
            'urgent' => 'bg-orange-100 text-orange-800',
            'planned' => 'bg-blue-100 text-blue-800',
            'maintenance' => 'bg-green-100 text-green-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Limit to "current" recommendations — those belonging to each senior's
     * latest ML result (max ml_result id per senior). Older assessments' recs
     * remain in the table as history but are excluded here. Correlated subquery
     * so it composes inside relationships, whereHas, withCount, and aggregates.
     */
    public function scopeCurrent($query)
    {
        return $query->whereRaw(
            'recommendations.ml_result_id = (select max(id) from ml_results where ml_results.senior_citizen_id = recommendations.senior_citizen_id)'
        );
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
