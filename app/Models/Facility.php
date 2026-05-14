<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Facility extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'barangay',
        'address',
        'latitude',
        'longitude',
        'source',
        'is_active',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_active' => 'boolean',
    ];

    public function nearestForHealthCenterMetrics(): HasMany
    {
        return $this->hasMany(SeniorAccessibilityMetric::class, 'nearest_health_center_id');
    }

    public function nearestForBarangayHallMetrics(): HasMany
    {
        return $this->hasMany(SeniorAccessibilityMetric::class, 'nearest_barangay_hall_id');
    }

    public function nearestForMarketMetrics(): HasMany
    {
        return $this->hasMany(SeniorAccessibilityMetric::class, 'nearest_market_id');
    }
}
