<?php

namespace App\Support;

class XaiFeatureLabels
{
    public static array $labels = [
        'sec1_age_risk' => 'Age Risk Factor',
        'sec2_family_support' => 'Family Support Score',
        'sec2_family_size_norm' => 'Household Size',
        'sec3_education_norm' => 'Education Level',
        'sec3_skill_score' => 'Skills & Training',
        'sec3_community_score' => 'Community Engagement',
        'sec3_hr_score' => 'Human Resources Score',
        'sec4_lives_alone' => 'Lives Alone',
        'sec4_household_risk' => 'Household Risk',
        'sec4_dependency_risk' => 'Dependency Risk',
        'sec5_income_norm' => 'Income Level',
        'sec5_real_asset_score' => 'Real Asset Score',
        'sec5_movable_asset_score' => 'Movable Asset Score',
        'sec5_income_source_score' => 'Income Source Diversity',
        'sec5_eco_stability' => 'Economic Stability',
        'sec6_phy_score' => 'Physical Health Score',
        'sec6_psy_score' => 'Psychological Health Score',
        'sec6_func_score' => 'Functional Health Score',
        'sec6_health_score' => 'Overall Health Score',
        'phy_energy' => 'Physical Energy',
        'phy_pain_r' => 'Freedom from Pain',
        'phy_health_limit_r' => 'Health Self-Care Ability',
        'phy_mobility_outside' => 'Mobility Outside Home',
        'phy_mobility_indoor' => 'Mobility Indoors',
        'psych_happiness' => 'Happiness & Positive Affect',
        'psych_peace' => 'Inner Peace & Calm',
        'psych_lonely_r' => 'Freedom from Loneliness',
        'psych_confidence' => 'Confidence & Self-Worth',
        'func_independence' => 'Functional Independence',
        'func_autonomy' => 'Time & Activity Autonomy',
        'func_control' => 'Life Control & Agency',
        'env_fin_medical' => 'Medical Affordability',
        'env_fin_household' => 'Household Expense Coverage',
        'env_fin_personal' => 'Personal Expense Coverage',
        'env_income_limit_r' => 'Freedom from Income Constraints',
        'env_safe_home' => 'Home Safety',
        'env_safe_neighborhood' => 'Neighborhood Safety',
        'env_home_comfort' => 'Home Comfort',
        'env_service_access' => 'Healthcare Service Access',
        'soc_social_support' => 'Social Support Network',
        'soc_close_friend' => 'Close Friendship',
        'soc_participation' => 'Community Participation',
        'soc_opportunity' => 'Social Opportunity',
        'soc_respect' => 'Sense of Respect & Dignity',
        'age' => 'Age',
        'education_enc' => 'Education Level',
        'income_enc' => 'Monthly Income Range',
        'has_pension' => 'Has Pension',
        'checkup_enc' => 'Medical Check-up Frequency',
        'living_with_count' => 'Household Members',
        'community_service_count' => 'Community Services Availed',
    ];

    public static function label(string $feature): string
    {
        return self::$labels[$feature] ?? ucwords(str_replace('_', ' ', $feature));
    }
}
