<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeniorFacilityRouteDistance extends Model
{
    use HasFactory;

    protected $fillable = [
        'senior_citizen_id',
        'facility_id',
        'origin_latitude',
        'origin_longitude',
        'destination_latitude',
        'destination_longitude',
        'route_distance_m',
        'route_duration_s',
        'provider',
        'calculated_at',
    ];

    protected $casts = [
        'origin_latitude' => 'decimal:7',
        'origin_longitude' => 'decimal:7',
        'destination_latitude' => 'decimal:7',
        'destination_longitude' => 'decimal:7',
        'route_distance_m' => 'decimal:2',
        'route_duration_s' => 'decimal:2',
        'calculated_at' => 'datetime',
    ];

    public function seniorCitizen(): BelongsTo
    {
        return $this->belongsTo(SeniorCitizen::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }
}
