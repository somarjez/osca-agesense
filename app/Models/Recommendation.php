<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recommendation extends Model
{
    protected $fillable = [
        'ml_result_id', 'senior_citizen_id',
        'priority', 'type', 'domain', 'category',
        'action', 'urgency', 'risk_level',
        'status', 'notes', 'target_date', 'assigned_to',
    ];

    protected $casts = [
        'target_date' => 'date',
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

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
