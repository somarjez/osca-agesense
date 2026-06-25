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
        'sec3_hr_score' => 'Overall Education & Skills',
        'sec4_lives_alone' => 'Lives Alone',
        'sec4_household_risk' => 'Household Risk',
        'sec4_dependency_risk' => 'Reliance on Others',
        'sec5_income_norm' => 'Income Level',
        'sec5_real_asset_score' => 'Property & Land Owned',
        'sec5_movable_asset_score' => 'Personal Belongings',
        'sec5_income_source_score' => 'Variety of Income Sources',
        'sec5_eco_stability' => 'Economic Stability',
        'sec6_phy_score' => 'Physical Health Score',
        'sec6_psy_score' => 'Mental Wellbeing Score',
        'sec6_func_score' => 'Daily Functioning Score',
        'sec6_health_score' => 'Overall Health Score',
        'phy_energy' => 'Physical Energy',
        'phy_pain_r' => 'Freedom from Pain',
        'phy_health_limit_r' => 'Health Self-Care Ability',
        'phy_mobility_outside' => 'Mobility Outside Home',
        'phy_mobility_indoor' => 'Mobility Indoors',
        'psych_happiness' => 'Happiness',
        'psych_peace' => 'Inner Peace & Calm',
        'psych_lonely_r' => 'Freedom from Loneliness',
        'psych_confidence' => 'Confidence & Self-Worth',
        'func_independence' => 'Independence',
        'func_autonomy' => 'Freedom Over Daily Activities',
        'func_control' => 'Sense of Control Over Life',
        'env_fin_medical' => 'Medical Affordability',
        'env_fin_household' => 'Household Expense Coverage',
        'env_fin_personal' => 'Personal Expense Coverage',
        'env_income_limit_r' => 'Enough Money for Needs',
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

    /**
     * Plain-language descriptions for the high-level XAI section groups
     * (mirrors _XAI_SECTION_GROUPS in python/services/inference_service.py).
     * Used to explain — in officer-friendly terms — what each risk driver covers.
     */
    public static array $sectionDescriptions = [
        'Physical Health' => 'general health, energy, pain, and medical check-up habits.',
        'Psychological' => 'mood, happiness, peace of mind, and sense of self-worth.',
        'Functional Health' => 'independence and the ability to carry out daily activities.',
        'Economic Stability' => 'income adequacy and the ability to cover everyday expenses.',
        'Social Connection' => 'relationships, community participation, and feeling respected.',
        'Environment' => 'home comfort and safety, neighbourhood, and service access.',
        'Family & Household' => 'household size, living arrangement, and family support.',
        'Human Capital' => 'education, skills, and community experience.',
        'Age & Demographics' => 'age and basic demographic factors.',
    ];

    public static function sectionDescription(string $section): string
    {
        return self::$sectionDescriptions[$section]
            ?? 'factors grouped under '.$section.'.';
    }

    /**
     * Plainer display names for the section groups, for LGU/officer readability.
     * Display-only — the canonical names (as stored in xai_data) are kept for
     * lookups in sectionForFeature() / sectionDescription().
     */
    public static array $sectionDisplayLabels = [
        'Psychological' => 'Mental & Emotional Wellbeing',
        'Functional Health' => 'Daily Functioning',
        'Economic Stability' => 'Finances',
        'Social Connection' => 'Social Life',
        'Human Capital' => 'Education & Skills',
        'Age & Demographics' => 'Age & Background',
        // Physical Health, Environment, Family & Household already read plainly.
    ];

    public static function sectionDisplayLabel(string $section): string
    {
        return self::$sectionDisplayLabels[$section] ?? $section;
    }

    /**
     * Section → member feature codes (mirrors _XAI_SECTION_GROUPS in
     * python/services/inference_service.py). Used to attribute a section-level
     * risk driver back to the specific features that cause it.
     */
    public static array $sectionGroups = [
        'Physical Health' => ['sec6_phy_score', 'sec6_health_score', 'checkup_enc', 'phy_energy', 'phy_pain_r',
            'phy_health_limit_r', 'phy_mobility_outside', 'phy_mobility_indoor'],
        'Psychological' => ['sec6_psy_score', 'psych_happiness', 'psych_peace',
            'psych_lonely_r', 'psych_confidence'],
        'Functional Health' => ['sec6_func_score', 'func_independence', 'func_autonomy', 'func_control'],
        'Economic Stability' => ['sec5_eco_stability', 'sec5_income_norm', 'sec5_real_asset_score',
            'sec5_movable_asset_score', 'sec5_income_source_score',
            'env_fin_medical', 'env_fin_household', 'env_fin_personal',
            'env_income_limit_r', 'income_enc', 'has_pension'],
        'Social Connection' => ['soc_social_support', 'soc_close_friend', 'soc_participation',
            'soc_opportunity', 'soc_respect'],
        'Environment' => ['env_safe_home', 'env_safe_neighborhood', 'env_home_comfort',
            'env_service_access'],
        'Family & Household' => ['sec2_family_support', 'sec2_family_size_norm', 'sec4_lives_alone',
            'sec4_household_risk', 'sec4_dependency_risk', 'living_with_count'],
        'Human Capital' => ['sec3_education_norm', 'sec3_skill_score', 'sec3_community_score',
            'sec3_hr_score', 'education_enc',
            'community_service_count'],
        'Age & Demographics' => ['sec1_age_risk', 'age'],
    ];

    public static function sectionForFeature(string $feature): string
    {
        foreach (self::$sectionGroups as $section => $features) {
            if (in_array($feature, $features, true)) {
                return $section;
            }
        }

        return 'Other';
    }
}
