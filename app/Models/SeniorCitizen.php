<?php

namespace App\Models;

use App\Casts\EncryptedOrPlainText;
use App\Support\CurrentMlResult;
use App\Support\DbHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SeniorCitizen extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * OSCA registration is restricted to actual senior citizens. Used to
     * bound date_of_birth validation on every user-facing registration path
     * (ProfileSurvey, BulkUploadController) and anywhere else "senior" needs
     * a concrete age floor. Deliberately not applied to the historical CSV
     * seeders (OscaCsvSeeder, MagdapioImportSeeder) — those reseed the fixed
     * dataset the notebook's cached validation artifacts are calibrated
     * against, so silently dropping a row there risks desyncing row counts
     * from that evidence rather than just rejecting bad new input.
     */
    public const MINIMUM_AGE = 60;

    /**
     * Auto-generate the public route-key UUID on creation. `uuid` is
     * intentionally excluded from $fillable — it is system-generated only.
     */
    protected static function booted(): void
    {
        static::creating(function (SeniorCitizen $senior) {
            if (empty($senior->uuid)) {
                $senior->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Resolve senior-facing routes ({senior} in seniors/surveys/ml/recommendations
     * routes) by uuid instead of the sequential integer id, so profile URLs don't
     * leak an enumerable identifier. Admin-only restore/force-delete routes take
     * an explicit integer {id} and are unaffected by this.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'osca_id', 'official_osca_id',
        'first_name', 'middle_name', 'last_name', 'name_extension',
        'barangay', 'date_of_birth', 'registration_date', 'contact_number', 'place_of_birth',
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
        'date_of_death', 'deceased_note', 'status_changed_by', 'status_changed_at',
        'ml_queued_at',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'registration_date' => 'date',
        'consent_given_at' => 'datetime',
        'date_of_death' => 'date',
        'status_changed_at' => 'datetime',
        'ml_queued_at' => 'datetime',
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

    /**
     * The official OSCA-ID isn't known for most seniors yet, so views render
     * this instead of the raw column to avoid every table/PDF re-implementing
     * the "Unassigned" placeholder.
     */
    public function getOfficialOscaIdDisplayAttribute(): string
    {
        return $this->official_osca_id ?? 'Unassigned';
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

    /**
     * "Latest" excludes an orphan — an ml_result whose qol_survey was
     * soft-deleted out from under it (see App\Support\CurrentMlResult) —
     * so this always resolves to the newest ml_result that's still backed by
     * a real, undeleted survey (or by no survey at all).
     */
    public function latestMlResult(): HasOne
    {
        return $this->hasOne(MlResult::class)->ofMany(['id' => 'max'], CurrentMlResult::excludeOrphaned());
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

    /**
     * Seniors marked deceased. Kept separate from the SoftDeletes archive —
     * these rows stay in the normal, queryable table; only the active-roster
     * scope above excludes them.
     */
    public function scopeDeceased($query)
    {
        return $query->where('status', 'deceased');
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

    /**
     * Case-insensitive search across name and both OSCA-ID columns — the
     * single source of truth every "search seniors" query should use.
     * Reusable both directly (`SeniorCitizen::searchTerm($term)`) and inside
     * a whereHas('seniorCitizen', ...) closure from another model.
     *
     * Plain `->where('col', 'like', "%{$term}%")` (used everywhere this scope
     * replaces) is case-INSENSITIVE on local MySQL's default collation, which
     * hid this defect through the entire audit — but the deployed production
     * database is Postgres (Neon; see .env), where LIKE is case-sensitive by
     * default. Staff searching "juan" for a senior stored as "Juan" got zero
     * results there. whereRaw('LOWER(...) LIKE ?', ...) is the one pattern
     * that behaves identically on both engines.
     */
    public function scopeSearchTerm($query, string $term)
    {
        $needle = '%'.strtolower($term).'%';

        return $query->where(function ($q) use ($needle) {
            $q->whereRaw('LOWER(first_name) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(last_name) LIKE ?', [$needle])
                ->orWhereRaw("LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?", [$needle])
                ->orWhereRaw('LOWER(osca_id) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(official_osca_id) LIKE ?', [$needle]);
        });
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
        $seqExpr = DbHelper::lastDashSegmentAsInt('osca_id');
        $maxSeq = (int) static::withTrashed()
            ->where('osca_id', 'like', "{$prefix}-{$year}-%")
            ->selectRaw("COALESCE(MAX({$seqExpr}), 0) AS m")
            ->value('m');

        $seq = str_pad($maxSeq + 1, 4, '0', STR_PAD_LEFT);

        return "{$prefix}-{$year}-{$seq}";
    }

    /**
     * The "{PREFIX}-{YEAR}-" portion of generateOscaId()'s output, exposed so
     * bulk-insert callers (BulkUploadController) can batch-fetch each distinct
     * prefix's current max sequence ONCE instead of running generateOscaId()'s
     * MAX(...) query per row — the same collision-avoidance rule, just queried
     * once per distinct barangay in a file instead of once per row.
     */
    public static function oscaIdPrefix(string $barangay): string
    {
        $special = [
            'Barangay I (Poblacion)' => 'BR1',
            'Barangay II (Poblacion)' => 'BR2',
        ];

        return $special[trim($barangay)]
            ?? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $barangay), 0, 3));
    }

    /**
     * Current max sequence number (not next — caller increments) for each
     * distinct prefix derived from the given barangays, for this year. Same
     * withTrashed() + LIKE + MAX(SUBSTRING_INDEX) rule as generateOscaId(),
     * batched to one query per distinct prefix rather than per row.
     *
     * @return array<string, int> prefix => current max sequence
     */
    public static function oscaIdMaxSequences(array $barangays): array
    {
        $year = now()->format('Y');
        $prefixes = array_unique(array_map(fn ($b) => static::oscaIdPrefix($b), $barangays));

        $seqExpr = DbHelper::lastDashSegmentAsInt('osca_id');
        $result = [];
        foreach ($prefixes as $prefix) {
            $result[$prefix] = (int) static::withTrashed()
                ->where('osca_id', 'like', "{$prefix}-{$year}-%")
                ->selectRaw("COALESCE(MAX({$seqExpr}), 0) AS m")
                ->value('m');
        }

        return $result;
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
