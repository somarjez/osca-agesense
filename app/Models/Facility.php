<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        'osm_id',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_active' => 'boolean',
    ];

    /**
     * Active facilities with usable coordinates, cached 24h — facilities change
     * rarely (only via the ImportOsmFacilities command), mirroring
     * GisApiController::barangayBoundaryFeatures()'s cache convention.
     */
    public static function cachedActiveWithCoordinates(): Collection
    {
        return Cache::remember(
            'facilities.active_with_coordinates',
            now()->addHours(24),
            fn () => static::query()
                ->where('is_active', DB::raw('true'))
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->orderBy('type')
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'barangay', 'latitude', 'longitude', 'source', 'osm_id'])
        );
    }

    public function nearestForHealthCenterMetrics(): HasMany
    {
        return $this->hasMany(SeniorAccessibilityMetric::class, 'nearest_health_center_id');
    }

    public function nearestForBarangayHallMetrics(): HasMany
    {
        return $this->hasMany(SeniorAccessibilityMetric::class, 'nearest_barangay_hall_id');
    }

    public function nearestForHospitalMetrics(): HasMany
    {
        return $this->hasMany(SeniorAccessibilityMetric::class, 'nearest_hospital_id');
    }

    public function nearestForPharmacyMetrics(): HasMany
    {
        return $this->hasMany(SeniorAccessibilityMetric::class, 'nearest_pharmacy_id');
    }

    public function nearestForMarketMetrics(): HasMany
    {
        return $this->hasMany(SeniorAccessibilityMetric::class, 'nearest_market_id');
    }

    public function seniorRouteDistances(): HasMany
    {
        return $this->hasMany(SeniorFacilityRouteDistance::class);
    }
}
