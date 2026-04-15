<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class QolSurvey extends Model
{
    protected $fillable = [
        'senior_citizen_id','survey_version','survey_date',
        'a1_enjoy_life','a2_life_satisfaction','a3_future_outlook','a4_meaningfulness',
        'b1_physical_energy','b2_pain_discomfort','b3_health_self_care',
        'b4_health_outside','b5_mobility',
        'c1_happiness','c2_calm_peace','c3_loneliness','c4_confidence',
        'd1_independence','d2_time_control','d3_life_control','d4_income_limits',
        'e1_social_support','e2_close_person','e3_community_opportunities',
        'e4_participation','e5_respect',
        'f1_home_safety','f2_neighborhood_safety','f3_service_access','f4_home_comfort',
        'g1_household_expenses','g2_medical_afford','g3_personal_wants',
        'h1_belief_comfort','h2_belief_practice',
        'score_qol','score_physical','score_psychological','score_independence',
        'score_social','score_environment','score_financial','score_spirituality',
        'overall_score','status',
    ];

    protected $casts = [
        'survey_date' => 'date',
    ];

    // Reverse-scored items (higher raw = lower QoL, so 6 - value)
    const REVERSE_SCORED = ['b2_pain_discomfort','b3_health_self_care','c3_loneliness','d4_income_limits'];

    const DOMAIN_ITEMS = [
        'qol'          => ['a1','a2','a3','a4'],
        'physical'     => ['b1','b2','b3','b4','b5'],
        'psychological'=> ['c1','c2','c3','c4'],
        'independence' => ['d1','d2','d3','d4'],
        'social'       => ['e1','e2','e3','e4','e5'],
        'environment'  => ['f1','f2','f3','f4'],
        'financial'    => ['g1','g2','g3'],
        'spirituality' => ['h1','h2'],
    ];

    public function seniorCitizen(): BelongsTo
    {
        return $this->belongsTo(SeniorCitizen::class);
    }

    public function mlResult(): HasOne
    {
        return $this->hasOne(MlResult::class, 'qol_survey_id');
    }

    /**
     * Compute and store all domain scores.
     */
    public function computeScores(): void
    {
        $domainScores = [];
        $allScores    = [];

        foreach (self::DOMAIN_ITEMS as $domain => $prefixes) {
            $vals = [];
            foreach ($prefixes as $prefix) {
                // Map prefix to full column (e.g., a1 → a1_enjoy_life)
                $col = collect($this->getFillable())
                    ->first(fn($f) => str_starts_with($f, $prefix . '_'));
                if (!$col) continue;

                $raw = $this->$col;
                if ($raw === null) continue;

                $isReversed = in_array($col, self::REVERSE_SCORED);
                $vals[] = $isReversed ? (6 - $raw) : $raw;
            }
            $score = count($vals) ? array_sum($vals) / count($vals) : null;
            $domainScores["score_{$domain}"] = $score ? round($score / 5, 3) : null; // normalize 0-1
            if ($score) $allScores[] = $score;
        }

        $domainScores['overall_score'] = count($allScores)
            ? round(array_sum($allScores) / (count($allScores) * 5), 3)
            : null;

        $this->update($domainScores + ['status' => 'processed']);
    }

    /**
     * Build the raw feature array to send to the Python preprocessing service.
     */
    public function toFeatureArray(): array
    {
        return [
            'qol_enjoy_life'        => $this->a1_enjoy_life,
            'qol_life_satisfaction' => $this->a2_life_satisfaction,
            'qol_future_outlook'    => $this->a3_future_outlook,
            'qol_meaningfulness'    => $this->a4_meaningfulness,
            'phy_energy'            => $this->b1_physical_energy,
            'phy_pain_r'            => $this->b2_pain_discomfort,
            'phy_health_limit_r'    => $this->b3_health_self_care,
            'phy_mobility_outside'  => $this->b4_health_outside,
            'phy_mobility_indoor'   => $this->b5_mobility,
            'psych_happiness'       => $this->c1_happiness,
            'psych_peace'           => $this->c2_calm_peace,
            'psych_lonely_r'        => $this->c3_loneliness,
            'psych_confidence'      => $this->c4_confidence,
            'func_independence'     => $this->d1_independence,
            'func_autonomy'         => $this->d2_time_control,
            'func_control'          => $this->d3_life_control,
            'env_income_limit_r'    => $this->d4_income_limits,
            'soc_social_support'    => $this->e1_social_support,
            'soc_close_friend'      => $this->e2_close_person,
            'soc_participation'     => $this->e4_participation,
            'soc_opportunity'       => $this->e3_community_opportunities,
            'soc_respect'           => $this->e5_respect,
            'env_safe_home'         => $this->f1_home_safety,
            'env_safe_neighborhood' => $this->f2_neighborhood_safety,
            'env_service_access'    => $this->f3_service_access,
            'env_home_comfort'      => $this->f4_home_comfort,
            'env_fin_medical'       => $this->g2_medical_afford,
            'env_fin_household'     => $this->g1_household_expenses,
            'env_fin_personal'      => $this->g3_personal_wants,
            'spi_belief_comfort'    => $this->h1_belief_comfort,
            'spi_belief_practice'   => $this->h2_belief_practice,
        ];
    }
}
