<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeniorAccessibilityMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'senior_citizen_id',
        'nearest_health_center_id',
        'distance_to_health_center_m',
        'nearest_barangay_hall_id',
        'distance_to_barangay_hall_m',
        'nearest_market_id',
        'distance_to_market_m',
        'accessibility_score',
        'calculated_at',
    ];

    protected $casts = [
        'distance_to_health_center_m' => 'decimal:2',
        'distance_to_barangay_hall_m' => 'decimal:2',
        'distance_to_market_m' => 'decimal:2',
        'accessibility_score' => 'decimal:4',
        'calculated_at' => 'datetime',
    ];

    public function seniorCitizen(): BelongsTo
    {
        return $this->belongsTo(SeniorCitizen::class);
    }

    public function nearestHealthCenter(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'nearest_health_center_id');
    }

    public function nearestBarangayHall(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'nearest_barangay_hall_id');
    }

    public function nearestMarket(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'nearest_market_id');
    }
}
