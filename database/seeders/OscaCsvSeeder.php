<?php

namespace Database\Seeders;

use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OscaCsvSeeder extends Seeder
{
    // ── Canonical value maps (CSV/Google Form text → UI option text) ─────────

    private const PROBLEMS_NEEDS_MAP = [
        // Income variants
        'Lack of source of income/resources' => 'Lack of income/resources',
        'Lack of source of income' => 'Lack of income/resources',
        'Lack of income' => 'Lack of income/resources',
        'Loss of source of income/resources' => 'Loss of income/resources',
        'Loss of source of income' => 'Loss of income/resources',
        'Loss of income' => 'Loss of income/resources',
        // Casing / wording fixes
        'Livelihood Opportunities' => 'Livelihood opportunities',
        'Health-Related Issues' => 'Health Related Issues',
        'Lack of access to health care services' => 'Lack of access to healthcare services',
        'High cost of medicine' => 'High cost of medicines',
        'Lack of Social Support' => 'Lack of social support',
        // Spelling / wording fixes
        'Limited Mobillity/Transportation' => 'Limited Mobility/Transportation difficulty',
        'Limited Mobility/Transportation' => 'Limited Mobility/Transportation difficulty',
    ];

    private const INCOME_SOURCE_MAP = [
        'Spouse Salary' => 'Spouse salary',
        'Spouse Pension' => 'Spouse pension',
    ];

    private const MEDICAL_CONCERN_MAP = [
        'Arthritis/Gout' => 'Arthritis / Gout',
        'Mental Health Condition (Depression/Anxiety)' => 'Mental Health Condition (Depression / Anxiety)',
        'Tuberculosis(TB)' => 'Tuberculosis (TB)',
        'Chronic Heart Disease' => 'Coronary Heart Disease',
        // Free-text conditions from Google Form → nearest canonical bucket
        'Heart Enlargement' => 'Coronary Heart Disease',
        'Scoliosis' => 'Physical Disability',
        'Prostate' => 'Other Chronic Disease',
        'Cholesterol' => 'Other Chronic Disease',
        'Anlodipin' => 'Other Chronic Disease',
        'Lungs' => 'Other Chronic Disease',
        'Mioma' => 'Other Chronic Disease',
        'Ulcer' => 'Other Chronic Disease',
        'Thyroid' => 'Other Chronic Disease',
        'Nagbabarang Puson' => 'Other Chronic Disease',
        'operated bato sa atay' => 'Other Chronic Disease',
    ];

    private const SOCIAL_EMOTIONAL_CONCERN_MAP = [
        'Feeling/Lonliness/Isolation' => 'Feeling/Loneliness/Isolation',
        'Living in healthy environment' => 'Living in a healthy environment',
        'Lack Social Support' => 'Lack social support',
        'Lack liesure/recreational activites' => 'Lack leisure activities',
        'Lack SC-friendly Environment' => 'Living in a healthy environment',
        // Free-text stress entries
        'Stress' => 'Feeling Depressed/Anxiety',
        'Apo stress' => 'Feeling Depressed/Anxiety',
    ];

    private const HEALTHCARE_DIFFICULTY_MAP = [
        'High cost of medicine' => 'High cost of medicines',
        'Difficulty in accessing health facilities' => 'Difficulty accessing health facilities',
        'Long waiting in health centers' => 'Long waiting time',
    ];

    private const OPTICAL_CONCERN_MAP = [
        'Blurred Vision' => 'Blurred vision',
        'Healthy eyes' => 'Healthy Eyes',
        // Free-text optical conditions from Google Form → nearest canonical bucket
        'Astigmatism' => 'Eye impairment',
        'half-eyed problem' => 'Eye impairment',
        'Ploaters' => 'Eye impairment',   // floaters
        'Eye Stroke' => 'Eye impairment',
        'Pugita Eye' => 'Eye impairment',
        'Malinaw pero malabo mata' => 'Blurred vision',  // Filipino: "clear but blurry eyes"
        'Maintenance patak' => 'Needs eye care',  // maintenance eye drops
        'Affected by diabetes' => 'Needs eye care',
    ];

    private const HEARING_CONCERN_MAP = [
        'Partial Hearing Loss' => 'Partial hearing loss',
        'Difficulty hearing converstaions' => 'Difficulty hearing conversations',
        'Hearing Impairment' => 'Hearing impairment',  // case fix
        'Needs hearing aid' => 'Uses hearing aid',
    ];

    private const PROBLEMS_NEEDS_EXTRA_MAP = [
        // Free-text entry preserved as custom "Others" value
        'Herbal' => 'Others: Herbal',
    ];

    public function run(): void
    {
        // Check project root first, then one level up (legacy placement)
        $csvPath = file_exists(base_path('osca.csv'))
            ? base_path('osca.csv')
            : base_path('../osca.csv');

        if (! file_exists($csvPath)) {
            $this->command->error('osca.csv not found. Place it in the project root or one folder above, then re-run.');

            return;
        }

        $this->command->info('Clearing current OSCA data...');
        $this->clearCurrentData();

        $this->command->info('Importing OSCA data from osca.csv...');

        $fp = fopen($csvPath, 'r');
        if (! $fp) {
            $this->command->error('Unable to open osca.csv');

            return;
        }

        $header = fgetcsv($fp);
        if (! $header) {
            fclose($fp);
            $this->command->error('osca.csv appears empty');

            return;
        }

        // Strip UTF-8 BOM from the first column header if present (Google Sheets / Excel export artefact)
        if (str_starts_with($header[0], "\xef\xbb\xbf")) {
            $header[0] = substr($header[0], 3);
        }

        // ── Pass 1: insert all seniors + surveys ──────────────────────────────
        $pairs = [];

        while (($line = fgetcsv($fp)) !== false) {
            $row = $this->rowToAssoc($header, $line);

            $senior = SeniorCitizen::create([
                'osca_id' => SeniorCitizen::generateOscaId((string) ($row['barangay'] ?? 'Unknown')),
                'first_name' => $this->strVal($row['first_name'] ?? null),
                'middle_name' => $this->strVal($row['middle_name'] ?? null),
                'last_name' => $this->strVal($row['last_name'] ?? null),
                'name_extension' => $this->strVal($row['name_ext'] ?? null),
                'barangay' => $this->strVal($row['barangay'] ?? null) ?: 'Unknown',
                'date_of_birth' => $this->parseDate($row['dob'] ?? null, dobMode: true),
                'contact_number' => $this->strVal($row['contact_number'] ?? null),
                'place_of_birth' => $this->strVal($row['place_of_birth'] ?? null),
                'marital_status' => $this->enumOrNull($row['marital_status'] ?? null, ['Single', 'Married', 'Widowed', 'Separated', 'Divorced', 'Annulled']),
                'gender' => $this->enumOrNull($row['gender'] ?? null, ['Male', 'Female', 'Prefer not to say']),
                'religion' => $this->strVal($row['religion'] ?? null),
                'ethnic_origin' => $this->strVal($row['ethnic_origin'] ?? null),
                'blood_type' => $this->strVal($row['blood_type'] ?? null),
                'num_children' => $this->intVal($row['num_children'] ?? null),
                'num_working_children' => $this->intVal($row['num_working_children'] ?? null),
                'child_financial_support' => $this->enumOrNull($row['child_financial_support'] ?? null, ['Yes', 'No', 'Occasional', 'N/A']),
                'spouse_working' => $this->enumOrNull($row['spouse_working'] ?? null, ['Yes', 'No', 'Deceased', 'N/A']),
                'household_size' => max(1, $this->intVal($row['household_size'] ?? null, 1)),
                'educational_attainment' => $this->strVal($row['education'] ?? null),
                'specialization' => $this->toList($row['specialization'] ?? null),
                'community_service' => $this->toList($row['community_service'] ?? null),
                'living_with' => $this->toList($row['living_with'] ?? null),
                'household_condition' => $this->toList($row['household_condition'] ?? null),
                'income_source' => $this->normalizeList($this->toList($row['income_source'] ?? null), self::INCOME_SOURCE_MAP),
                'real_assets' => $this->toList($row['real_assets'] ?? null),
                'movable_assets' => $this->toList($row['movable_assets'] ?? null),
                'monthly_income_range' => $this->normalizeIncomeRange($row['monthly_income_range'] ?? null),
                'problems_needs' => $this->normalizeList($this->normalizeList($this->toList($row['problems_needs'] ?? null), self::PROBLEMS_NEEDS_MAP), self::PROBLEMS_NEEDS_EXTRA_MAP),
                'medical_concern' => $this->normalizeList($this->toList($row['medical_concern'] ?? null), self::MEDICAL_CONCERN_MAP),
                'dental_concern' => $this->toList($row['dental_concern'] ?? null),
                'optical_concern' => $this->normalizeList($this->toList($row['optical_concern'] ?? null), self::OPTICAL_CONCERN_MAP),
                'hearing_concern' => $this->normalizeList($this->toList($row['hearing_concern'] ?? null), self::HEARING_CONCERN_MAP),
                'social_emotional_concern' => $this->normalizeList($this->toList($row['social_emotional_concern'] ?? null), self::SOCIAL_EMOTIONAL_CONCERN_MAP),
                'healthcare_difficulty' => $this->normalizeList($this->toList($row['healthcare_difficulty'] ?? null), self::HEALTHCARE_DIFFICULTY_MAP),
                'has_medical_checkup' => $this->boolVal($row['has_medical_checkup'] ?? null),
                'checkup_schedule' => $this->strVal($row['checkup_schedule'] ?? null),
                'status' => 'active',
                'encoded_by' => 'CSV Import',
            ]);

            $surveyDate = $this->parseDate($row['timestamp'] ?? null) ?? now()->format('Y-m-d');
            $survey = QolSurvey::create([
                'senior_citizen_id' => $senior->id,
                'survey_version' => 'v1',
                'survey_date' => $surveyDate,
                'a1_enjoy_life' => $this->scoreVal($row['qol_enjoy_life'] ?? null),
                'a2_life_satisfaction' => $this->scoreVal($row['qol_life_satisfaction'] ?? null),
                'a3_future_outlook' => $this->scoreVal($row['qol_future_outlook'] ?? null),
                'a4_meaningfulness' => $this->scoreVal($row['qol_meaningfulness'] ?? null),
                'b1_physical_energy' => $this->scoreVal($row['phy_energy'] ?? null),
                'b2_pain_discomfort' => $this->scoreVal($row['phy_pain_r'] ?? null),
                'b3_health_self_care' => $this->scoreVal($row['phy_health_limit_r'] ?? null),
                'b4_health_outside' => $this->scoreVal($row['phy_mobility_outside'] ?? null),
                'b5_mobility' => $this->scoreVal($row['phy_mobility_indoor'] ?? null),
                'c1_happiness' => $this->scoreVal($row['psych_happiness'] ?? null),
                'c2_calm_peace' => $this->scoreVal($row['psych_peace'] ?? null),
                'c3_loneliness' => $this->scoreVal($row['psych_lonely_r'] ?? null),
                'c4_confidence' => $this->scoreVal($row['psych_confidence'] ?? null),
                'd1_independence' => $this->scoreVal($row['func_independence'] ?? null),
                'd2_time_control' => $this->scoreVal($row['func_autonomy'] ?? null),
                'd3_life_control' => $this->scoreVal($row['func_control'] ?? null),
                'd4_income_limits' => $this->scoreVal($row['env_income_limit_r'] ?? null),
                'e1_social_support' => $this->scoreVal($row['soc_social_support'] ?? null),
                'e2_close_person' => $this->scoreVal($row['soc_close_friend'] ?? null),
                'e3_community_opportunities' => $this->scoreVal($row['soc_participation'] ?? null),
                'e4_participation' => $this->scoreVal($row['soc_opportunity'] ?? null),
                'e5_respect' => $this->scoreVal($row['soc_respect'] ?? null),
                'f1_home_safety' => $this->scoreVal($row['env_safe_home'] ?? null),
                'f2_neighborhood_safety' => $this->scoreVal($row['env_safe_neighborhood'] ?? null),
                'f3_service_access' => $this->scoreVal($row['env_service_access'] ?? null),
                'f4_home_comfort' => $this->scoreVal($row['env_home_comfort'] ?? null),
                'g1_household_expenses' => $this->scoreVal($row['env_fin_household'] ?? null),
                'g2_medical_afford' => $this->scoreVal($row['env_fin_medical'] ?? null),
                'g3_personal_wants' => $this->scoreVal($row['env_fin_personal'] ?? null),
                'h1_belief_comfort' => $this->scoreVal($row['spi_belief_comfort'] ?? null),
                'h2_belief_practice' => $this->scoreVal($row['spi_belief_practice'] ?? null),
                'status' => 'submitted',
            ]);

            $survey->computeScores();
            $pairs[] = ['senior' => $senior, 'survey' => $survey];
        }

        fclose($fp);

        $rows = count($pairs);
        $this->command->info("Inserted {$rows} seniors + surveys. Running ML batch pipeline...");

        // ── Pass 2: one Python subprocess for all seniors ─────────────────────
        $mlService = app(MlService::class);
        $results = $mlService->runBatchPipeline($pairs);

        $mlSuccess = count(array_filter($results, fn ($r) => $r['success'] && ! str_contains(strtolower((string) ($r['result']?->raw_output['status'] ?? '')), 'fallback')));
        $mlFallback = count(array_filter($results, fn ($r) => $r['success'] && str_contains(strtolower((string) ($r['result']?->raw_output['status'] ?? '')), 'fallback')));
        $mlErrors = count(array_filter($results, fn ($r) => ! $r['success']));

        $this->command->info("Imported rows:  {$rows}");
        $this->command->info("ML success: {$mlSuccess}, fallback: {$mlFallback}, errors: {$mlErrors}");
    }

    private function clearCurrentData(): void
    {
        DB::transaction(function () {
            DB::table('recommendations')->delete();
            DB::table('ml_results')->delete();
            DB::table('qol_surveys')->delete();
            DB::table('senior_citizens')->delete();
            DB::table('cluster_snapshots')->delete();

            if (DB::getDriverName() === 'sqlite') {
                foreach (['recommendations', 'ml_results', 'qol_surveys', 'senior_citizens', 'cluster_snapshots'] as $table) {
                    DB::statement("DELETE FROM sqlite_sequence WHERE name = '{$table}'");
                }
            }
        });
    }

    private function rowToAssoc(array $header, array $line): array
    {
        $assoc = [];
        foreach ($header as $idx => $key) {
            $assoc[$key] = $line[$idx] ?? null;
        }

        return $assoc;
    }

    private function strVal($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);
        if ($v === '' || strtolower($v) === 'nan') {
            return null;
        }

        return $v;
    }

    private function intVal($value, int $default = 0): int
    {
        if ($value === null || $value === '' || strtolower((string) $value) === 'nan') {
            return $default;
        }

        return (int) round((float) $value);
    }

    private function boolVal($value): bool
    {
        $v = strtolower((string) ($this->strVal($value) ?? ''));

        return in_array($v, ['1', 'true', 'yes', 'y'], true);
    }

    private function enumOrNull($value, array $allowed): ?string
    {
        $v = $this->strVal($value);
        if ($v === null) {
            return null;
        }
        foreach ($allowed as $opt) {
            if (strcasecmp($opt, $v) === 0) {
                return $opt;
            }
        }

        return null;
    }

    private function toList($value): array
    {
        $v = $this->strVal($value);
        if ($v === null) {
            return [];
        }
        $parts = array_map('trim', explode(',', $v));

        return array_values(array_filter($parts, fn ($x) => $x !== ''));
    }

    /**
     * Parse a date string from CSV.
     *
     * @param  bool  $dobMode  When true, a value containing a time suffix (e.g. " 0:00") is treated
     *                         as a Google-Form date-picker export in Philippine locale (d/m/Y).
     *                         Set to false for timestamps, which Google exports in m/d/Y H:i.
     */
    private function parseDate($value, bool $dobMode = false): ?string
    {
        $v = $this->strVal($value);
        if ($v === null) {
            return null;
        }

        // Regex intentionally omits $ so it matches even when a time suffix like " 0:00" is present.
        $hasSlashDate = preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $v, $m);

        if ($hasSlashDate) {
            $dateOnly = "{$m[1]}/{$m[2]}/{$m[3]}";
            $hasTimeSuffix = (bool) preg_match('/\s+\d/', $v);

            if ((int) $m[1] > 12) {
                // Day-first unambiguous (day can't be a month): always d/m/Y.
                try {
                    return Carbon::createFromFormat('d/m/Y', $dateOnly)->format('Y-m-d');
                } catch (\Throwable $e) {
                }
            } elseif ($dobMode && $hasTimeSuffix) {
                // DOB from Google Form date-picker: Philippine locale exports d/m/Y H:i.
                // Strip the time and parse as d/m/Y regardless of whether day ≤ 12.
                try {
                    return Carbon::createFromFormat('d/m/Y', $dateOnly)->format('Y-m-d');
                } catch (\Throwable $e) {
                }
            }
        }

        // Fallback: try common formats. Timestamps are m/d/Y H:i (Google's export locale).
        $formats = ['m/d/Y H:i', 'm/d/Y', 'Y-m-d', 'd/m/Y'];
        foreach ($formats as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $v)->format('Y-m-d');
            } catch (\Throwable $e) {
            }
        }

        try {
            return Carbon::parse($v)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function scoreVal($value): ?int
    {
        if ($value === null || $value === '' || strtolower((string) $value) === 'nan') {
            return null;
        }
        $n = (int) round((float) $value);

        return max(1, min(5, $n));
    }

    private function normalizeList(array $items, array $map): array
    {
        return array_values(array_map(fn ($item) => $map[$item] ?? $item, $items));
    }

    private function normalizeIncomeRange($value): ?string
    {
        $v = $this->strVal($value);
        if ($v === null) {
            return null;
        }

        if ($v === '60, 000 and above') {
            return '60,000 and above';
        }

        return $v;
    }
}
