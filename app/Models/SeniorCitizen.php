<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'status', 'encoded_by',
        'consent_given_at', 'consent_method',
    ];

    protected $casts = [
        'date_of_birth'          => 'date',
        'consent_given_at'       => 'datetime',
        'has_medical_checkup'    => 'boolean',
        // Non-searchable PII encrypted at rest
        'contact_number'         => 'encrypted',
        'place_of_birth'         => 'encrypted',
        'philsys_id'             => 'encrypted',
        'specialization'         => 'array',
        'community_service'      => 'array',
        'living_with'            => 'array',
        'household_condition'    => 'array',
        'income_source'          => 'array',
        'real_assets'            => 'array',
        'movable_assets'         => 'array',
        'medical_concern'          => 'array',
        'dental_concern'           => 'array',
        'optical_concern'          => 'array',
        'hearing_concern'          => 'array',
        'healthcare_difficulty'    => 'array',
        'social_emotional_concern' => 'array',
        'problems_needs'           => 'array',
    ];

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        $parts = array_filter([
            $this->first_name,
            $this->middle_name ? mb_substr($this->middle_name, 0, 1) . '.' : null,
            $this->last_name,
            $this->name_extension,
        ]);
        return implode(' ', $parts);
    }

    public function getAgeAttribute(): int
    {
        return $this->date_of_birth?->diffInYears(now()) ?? 0;
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
        return $query->whereHas('latestMlResult', fn($q) =>
            $q->where('overall_risk_level', strtoupper($level))
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function generateOscaId(string $barangay): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $barangay), 0, 3));
        $year   = now()->format('Y');
        $seq    = str_pad(static::where('osca_id', 'like', "{$prefix}-{$year}-%")->count() + 1, 4, '0', STR_PAD_LEFT);
        return "{$prefix}-{$year}-{$seq}";
    }

    public static function barangayList(): array
    {
        return [
            'Anibong','Biñan','Buboy','Calusiche','Cabanbanan',
            'Dingin','Lambac','Layugan','Magdapio','Maulawin',
            'Pinagsanjan',
            'Barangay I (Poblacion)','Barangay II (Poblacion)',
            'Sabang','Sampaloc','San Isidro',
        ];
    }
}
