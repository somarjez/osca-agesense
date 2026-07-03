<?php

namespace App\Models;

use App\Casts\EncryptedOrPlainText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class SeniorCitizen extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'osca_id',
        'first_name', 'middle_name', 'last_name', 'name_extension',
        'barangay', 'date_of_birth', 'contact_number', 'place_of_birth',
        'marital_status', 'gender', 'religion', 'ethnic_origin',
        'blood_type', 'philsys_id',
        'num_children', 'num_working_children',
        'child_financial_support', 'spouse_working', 'household_size',
        'educational_attainment', 'specialization', 'community_service',
        'living_with', 'household_condition',
        'income_source', 'real_assets', 'movable_assets',
        'monthly_income_range', 'problems_needs',
        'medical_concern', 'dental_concern', 'optical_concern',
        'hearing_concern', 'social_emotional_concern',
        'healthcare_difficulty', 'has_medical_checkup', 'checkup_schedule',
        'latitude', 'longitude', 'location_source', 'location_accuracy', 'location_verified_at',
        'status', 'encoded_by',
        'consent_given_at', 'consent_method',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'consent_given_at' => 'datetime',
        'has_medical_checkup' => 'boolean',
        // Non-searchable PII encrypted at rest
        'contact_number' => EncryptedOrPlainText::class,
        'place_of_birth' => EncryptedOrPlainText::class,
        'philsys_id' => EncryptedOrPlainText::class,
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'location_verified_at' => 'datetime',
        'specialization' => 'array',
        'community_service' => 'array',
        'living_with' => 'array',
        'household_condition' => 'array',
        'income_source' => 'array',
        'real_assets' => 'array',
        'movable_assets' => 'array',
        'medical_concern' => 'array',
        'dental_concern' => 'array',
        'optical_concern' => 'array',
        'hearing_concern' => 'array',
        'healthcare_difficulty' => 'array',
        'social_emotional_concern' => 'array',
        'problems_needs' => 'array',
    ];

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        $parts = array_filter([
            $this->first_name,
            $this->middle_name ? mb_substr($this->middle_name, 0, 1).'.' : null,
            $this->last_name,
            $this->name_extension,
        ]);

        return implode(' ', $parts);
    }

    public function getAgeAttribute(): int
    {
        // Export queries compute age via SQL (TIMESTAMPDIFF) and alias it as 'age'.
        // When that SQL value is present, use it directly — date_of_birth is not
        // selected in those queries, so the Carbon fallback would return 0.
        if (array_key_exists('age', $this->attributes) && $this->attributes['age'] !== null) {
            return (int) $this->attributes['age'];
        }

        // Full model loads (profile page, PDF, show view) use the Carbon path.
        return $this->date_of_birth?->diffInYears(now()) ?? 0;
    }

    /**
     * Resolve how this senior's location should be presented on the profile.
     *
     * Returns a small view-model the profile and any map can render directly:
     *   status  — 'verified'    a field-captured GPS pin (exact home location)
     *             'approximate' barangay-level coordinate, no verified pin
     *             'none'        no coordinate stored yet (not geocoded)
     *   lat/lng — floats when present, otherwise null
     *   label   — staff-facing precision note
     *
     * Verified sources mirror GisApiController / GeocodeSeniors so the profile,
     * the map, and the geocode pipeline agree on what "verified" means.
     */
    public function locationDisplay(): array
    {
        $lat = $this->latitude !== null ? (float) $this->latitude : null;
        $lng = $this->longitude !== null ? (float) $this->longitude : null;

        // Reject missing, out-of-range, or null-island (0,0) coordinates — same
        // validity bar GisApiController applies before plotting a senior.
        $valid = $lat !== null && $lng !== null
            && $lat >= -90 && $lat <= 90
            && $lng >= -180 && $lng <= 180
            && ! ($lat === 0.0 && $lng === 0.0);

        if (! $valid) {
            return [
                'status' => 'none',
                'lat' => null,
                'lng' => null,
                'label' => 'No location recorded yet',
                'source' => $this->location_source,
            ];
        }

        $source = strtolower((string) $this->location_source);
        $verified = in_array($source, ['manual_pin', 'gps_capture'], true);

        return [
            'status' => $verified ? 'verified' : 'approximate',
            'lat' => $lat,
            'lng' => $lng,
            'label' => $verified
                ? 'Field-verified GPS pin'
                : 'Approximate — barangay-level, no GPS pin',
            'source' => $this->location_source,
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function qolSurveys(): HasMany
    {
        return $this->hasMany(QolSurvey::class);
    }

    public function latestQolSurvey(): HasOne
    {
        return $this->hasOne(QolSurvey::class)->latestOfMany();
    }

    public function profileDraft(): HasOne
    {
        return $this->hasOne(ProfileDraft::class);
    }

    public function mlResults(): HasMany
    {
        return $this->hasMany(MlResult::class);
    }

    public function latestMlResult(): HasOne
    {
        return $this->hasOne(MlResult::class)->latestOfMany();
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    /**
     * Recommendations from the senior's latest ML result only (current assessment).
     * Reuses Recommendation::scopeCurrent so the "latest" definition lives in one place.
     */
    public function currentRecommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class)->current();
    }

    public function accessibilityMetrics(): HasMany
    {
        return $this->hasMany(SeniorAccessibilityMetric::class);
    }

    public function facilityRouteDistances(): HasMany
    {
        return $this->hasMany(SeniorFacilityRouteDistance::class);
    }

    public function accessibilityMetric(): HasOne
    {
        return $this->hasOne(SeniorAccessibilityMetric::class);
    }

    public function latestAccessibilityMetric(): HasOne
    {
        return $this->hasOne(SeniorAccessibilityMetric::class)->latestOfMany();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByBarangay($query, string $barangay)
    {
        return $query->where('barangay', $barangay);
    }

    public function scopeByRiskLevel($query, string $level)
    {
        return $query->whereHas('latestMlResult', fn ($q) => $q->where('overall_risk_level', strtoupper($level))
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function generateOscaId(string $barangay): string
    {
        // The two Poblacion barangays both reduce to "BAR" under the 3-letter rule
        // and would share one sequence; give them distinct prefixes instead.
        $special = [
            'Barangay I (Poblacion)' => 'BR1',
            'Barangay II (Poblacion)' => 'BR2',
        ];
        $prefix = $special[trim($barangay)]
            ?? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $barangay), 0, 3));
        $year = now()->format('Y');

        // Use the highest existing sequence — including soft-deleted rows, which the
        // unique index still enforces — rather than count()+1. A row count collides
        // with trashed rows and any gaps left by deletions (e.g. BAR-2026-0037).
        $maxSeq = (int) static::withTrashed()
            ->where('osca_id', 'like', "{$prefix}-{$year}-%")
            ->selectRaw('COALESCE(MAX(CAST(SUBSTRING_INDEX(osca_id, "-", -1) AS UNSIGNED)), 0) AS m')
            ->value('m');

        $seq = str_pad($maxSeq + 1, 4, '0', STR_PAD_LEFT);

        return "{$prefix}-{$year}-{$seq}";
    }

    public static function barangayList(): array
    {
        return [
            'Anibong', 'Biñan', 'Buboy', 'Calusiche', 'Cabanbanan',
            'Dingin', 'Lambac', 'Layugan', 'Magdapio', 'Maulawin',
            'Pinagsanjan',
            'Barangay I (Poblacion)', 'Barangay II (Poblacion)',
            'Sabang', 'Sampaloc', 'San Isidro',
        ];
    }
}
