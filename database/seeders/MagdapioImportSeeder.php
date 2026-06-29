<?php

namespace Database\Seeders;

use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Non-destructive seeder for the 70-row Magdapio/Pagsanjan CSV batch.
 *
 * Reads osca_normalized.csv (360 rows), skips any row whose
 * first_name + last_name + date_of_birth + barangay already exists in the DB,
 * and inserts only the genuinely new records.  Does NOT call clearCurrentData()
 * and does NOT auto-run the ML pipeline (use ml:repair-notebook-cache --all
 * after seeding to score all seniors consistently against the retrained model).
 *
 * Run: php artisan db:seed --class=MagdapioImportSeeder
 */
class MagdapioImportSeeder extends Seeder
{
    // ── Canonical value maps (mirrors OscaCsvSeeder) ─────────────────────────
    // Prostate / Pneumonia / Sinusitis are now canonical form options → NOT remapped.
    // Sinusutis (data typo) IS remapped to the canonical Sinusitis.

    private const MEDICAL_CONCERN_MAP = [
        'Arthritis/Gout'                                        => 'Arthritis / Gout',
        'Mental Health Condition (Depression/Anxiety)'          => 'Mental Health Condition (Depression / Anxiety)',
        'Tuberculosis(TB)'                                      => 'Tuberculosis (TB)',
        'Chronic Heart Disease'                                 => 'Coronary Heart Disease',
        'Heart Enlargement'                                     => 'Coronary Heart Disease',
        'Scoliosis'                                             => 'Physical Disability',
        'Sinusutis'                                             => 'Sinusitis',   // data typo
        'Cholesterol'                                           => 'Other Chronic Disease',
        'Anlodipin'                                             => 'Other Chronic Disease',
        'Lungs'                                                 => 'Other Chronic Disease',
        'Mioma'                                                 => 'Other Chronic Disease',
        'Ulcer'                                                 => 'Other Chronic Disease',
        'Thyroid'                                               => 'Other Chronic Disease',
        'Nagbabarang Puson'                                     => 'Other Chronic Disease',
        'operated bato sa atay'                                 => 'Other Chronic Disease',
    ];

    private const SOCIAL_EMOTIONAL_CONCERN_MAP = [
        'Feeling/Lonliness/Isolation'      => 'Feeling/Loneliness/Isolation',
        'Living in healthy environment'    => 'Living in a healthy environment',
        'Lack Social Support'              => 'Lack social support',
        'Lack liesure/recreational activites' => 'Lack leisure activities',
        'Lack SC-friendly Environment'     => 'Living in a healthy environment',
        'Stress'                           => 'Feeling Depressed/Anxiety',
        'Apo stress'                       => 'Feeling Depressed/Anxiety',
    ];

    private const HEALTHCARE_DIFFICULTY_MAP = [
        'High cost of medicine'                          => 'High cost of medicines',
        'Difficulty in accessing health facilities'      => 'Difficulty accessing health facilities',
        'Long waiting in health centers'                 => 'Long waiting time',
    ];

    private const OPTICAL_CONCERN_MAP = [
        'Blurred Vision'             => 'Blurred vision',
        'Healthy eyes'               => 'Healthy Eyes',
        'Astigmatism'                => 'Eye impairment',
        'half-eyed problem'          => 'Eye impairment',
        'Ploaters'                   => 'Eye impairment',
        'Eye Stroke'                 => 'Eye impairment',
        'Pugita Eye'                 => 'Eye impairment',
        'Malinaw pero malabo mata'   => 'Blurred vision',
        'Maintenance patak'          => 'Needs eye care',
        'Affected by diabetes'       => 'Needs eye care',
    ];

    private const HEARING_CONCERN_MAP = [
        'Partial Hearing Loss'              => 'Partial hearing loss',
        'Difficulty hearing converstaions'  => 'Difficulty hearing conversations',
        'Hearing Impairment'                => 'Hearing impairment',
        'Needs hearing aid'                 => 'Uses hearing aid',
    ];

    private const MARITAL_STATUS_MAP = [
        'Legally Separated' => 'Separated',
    ];

    private const INCOME_SOURCE_MAP = [
        'Spouse Salary'   => 'Spouse salary',
        'Spouse Pension'  => 'Spouse pension',
    ];

    private const PROBLEMS_NEEDS_MAP = [
        'Lack of source of income/resources'       => 'Lack of income/resources',
        'Lack of source of income'                 => 'Lack of income/resources',
        'Lack of income'                           => 'Lack of income/resources',
        'Loss of source of income/resources'       => 'Loss of income/resources',
        'Loss of source of income'                 => 'Loss of income/resources',
        'Loss of income'                           => 'Loss of income/resources',
        'Livelihood Opportunities'                 => 'Livelihood opportunities',
        'Health-Related Issues'                    => 'Health Related Issues',
        'Lack of access to health care services'   => 'Lack of access to healthcare services',
        'High cost of medicine'                    => 'High cost of medicines',
        'Lack of Social Support'                   => 'Lack of social support',
        'Limited Mobillity/Transportation'         => 'Limited Mobility/Transportation difficulty',
        'Limited Mobility/Transportation'          => 'Limited Mobility/Transportation difficulty',
    ];

    // ─────────────────────────────────────────────────────────────────────────

    public function run(): void
    {
        $csvPath = file_exists(base_path('osca_normalized.csv'))
            ? base_path('osca_normalized.csv')
            : base_path('../osca_normalized.csv');

        if (! file_exists($csvPath)) {
            $this->command->error('osca_normalized.csv not found. Expected at the project root or one folder above.');

            return;
        }

        $this->command->info("Reading: {$csvPath}");

        $fp = fopen($csvPath, 'r');
        if (! $fp) {
            $this->command->error('Unable to open osca_normalized.csv');

            return;
        }

        // Strip BOM if present
        $firstBytes = fread($fp, 3);
        if ($firstBytes !== "\xef\xbb\xbf") {
            rewind($fp);
        }

        $header = fgetcsv($fp);
        if (! $header) {
            fclose($fp);
            $this->command->error('osca_normalized.csv appears empty');

            return;
        }

        $inserted       = 0;
        $skippedDup     = 0;
        $skippedMissing = 0;

        while (($line = fgetcsv($fp)) !== false) {
            $row = $this->rowToAssoc($header, $line);

            // ── Required-field guard ────────────────────────────────────────
            $firstName = $this->strVal($row['first_name'] ?? null);
            $lastName  = $this->strVal($row['last_name']  ?? null);
            $barangay  = $this->strVal($row['barangay']   ?? null);
            $dob       = $this->parseDate($row['dob'] ?? null, dobMode: true);

            if (! $firstName || ! $lastName || ! $barangay || ! $dob) {
                $skippedMissing++;
                $this->command->warn("  SKIP (missing required): {$firstName} {$lastName} / {$barangay}");
                continue;
            }

            // ── Dedupe guard (mirrors BulkUploadController, line 246-257) ──
            $alreadyExists = SeniorCitizen::where('first_name', $firstName)
                ->where('last_name', $lastName)
                ->where('date_of_birth', $dob)
                ->where('barangay', $barangay)
                ->exists();

            if ($alreadyExists) {
                $skippedDup++;
                continue;
            }

            // ── Insert senior ───────────────────────────────────────────────
            $senior = SeniorCitizen::create([
                'osca_id'                   => SeniorCitizen::generateOscaId($barangay),
                'first_name'                => $firstName,
                'middle_name'               => $this->strVal($row['middle_name'] ?? null),
                'last_name'                 => $lastName,
                'name_extension'            => null,
                'barangay'                  => $barangay,
                'date_of_birth'             => $dob,
                'contact_number'            => null,
                'place_of_birth'            => null,
                'marital_status'            => $this->enumOrNull(self::MARITAL_STATUS_MAP[$row['marital_status'] ?? ''] ?? ($row['marital_status'] ?? null), ['Single', 'Married', 'Widowed', 'Separated', 'Divorced', 'Annulled']),
                'gender'                    => $this->enumOrNull($row['gender'] ?? null, ['Male', 'Female', 'Prefer not to say']),
                'religion'                  => null,
                'ethnic_origin'             => null,
                'blood_type'                => null,
                'num_children'              => $this->intVal($row['num_children'] ?? null),
                'num_working_children'      => $this->intVal($row['num_working_children'] ?? null),
                'child_financial_support'   => $this->enumOrNull($row['child_financial_support'] ?? null, ['Yes', 'No', 'Occasional', 'N/A']),
                'spouse_working'            => $this->enumOrNull($row['spouse_working'] ?? null, ['Yes', 'No', 'Deceased', 'N/A']),
                'household_size'            => max(1, $this->intVal($row['household_size'] ?? null, 1)),
                'educational_attainment'    => $this->strVal($row['education'] ?? null),
                'specialization'            => $this->toList($row['specialization'] ?? null),
                'community_service'         => $this->toList($row['community_service'] ?? null),
                'living_with'               => $this->toList($row['living_with'] ?? null),
                'household_condition'       => $this->toList($row['household_condition'] ?? null),
                'income_source'             => $this->normalizeList($this->toList($row['income_source'] ?? null), self::INCOME_SOURCE_MAP),
                'real_assets'               => $this->toList($row['real_assets'] ?? null),
                'movable_assets'            => $this->toList($row['movable_assets'] ?? null),
                'monthly_income_range'      => $this->normalizeIncomeRange($row['monthly_income_range'] ?? null),
                'problems_needs'            => $this->normalizeList($this->toList($row['problems_needs'] ?? null), self::PROBLEMS_NEEDS_MAP),
                'medical_concern'           => $this->normalizeList($this->toList($row['medical_concern'] ?? null), self::MEDICAL_CONCERN_MAP),
                'dental_concern'            => $this->toList($row['dental_concern'] ?? null),
                'optical_concern'           => $this->normalizeList($this->toList($row['optical_concern'] ?? null), self::OPTICAL_CONCERN_MAP),
                'hearing_concern'           => $this->normalizeList($this->toList($row['hearing_concern'] ?? null), self::HEARING_CONCERN_MAP),
                'social_emotional_concern'  => $this->normalizeList($this->toList($row['social_emotional_concern'] ?? null), self::SOCIAL_EMOTIONAL_CONCERN_MAP),
                'healthcare_difficulty'     => $this->normalizeList($this->toList($row['healthcare_difficulty'] ?? null), self::HEALTHCARE_DIFFICULTY_MAP),
                'has_medical_checkup'       => $this->boolVal($row['has_medical_checkup'] ?? null),
                'checkup_schedule'          => $this->strVal($row['checkup_schedule'] ?? null),
                'status'                    => 'active',
                'encoded_by'                => 'CSV Import',
            ]);

            // ── Insert QoL survey ───────────────────────────────────────────
            $surveyDate = $this->parseDate($row['timestamp'] ?? null) ?? now()->format('Y-m-d');
            $survey = QolSurvey::create([
                'senior_citizen_id'           => $senior->id,
                'survey_version'              => 'v1',
                'survey_date'                 => $surveyDate,
                'a1_enjoy_life'               => $this->scoreVal($row['qol_enjoy_life'] ?? null),
                'a2_life_satisfaction'        => $this->scoreVal($row['qol_life_satisfaction'] ?? null),
                'a3_future_outlook'           => $this->scoreVal($row['qol_future_outlook'] ?? null),
                'a4_meaningfulness'           => $this->scoreVal($row['qol_meaningfulness'] ?? null),
                'b1_physical_energy'          => $this->scoreVal($row['phy_energy'] ?? null),
                'b2_pain_discomfort'          => $this->scoreVal($row['phy_pain_r'] ?? null),
                'b3_health_self_care'         => $this->scoreVal($row['phy_health_limit_r'] ?? null),
                'b4_health_outside'           => $this->scoreVal($row['phy_mobility_outside'] ?? null),
                'b5_mobility'                 => $this->scoreVal($row['phy_mobility_indoor'] ?? null),
                'c1_happiness'                => $this->scoreVal($row['psych_happiness'] ?? null),
                'c2_calm_peace'               => $this->scoreVal($row['psych_peace'] ?? null),
                'c3_loneliness'               => $this->scoreVal($row['psych_lonely_r'] ?? null),
                'c4_confidence'               => $this->scoreVal($row['psych_confidence'] ?? null),
                'd1_independence'             => $this->scoreVal($row['func_independence'] ?? null),
                'd2_time_control'             => $this->scoreVal($row['func_autonomy'] ?? null),
                'd3_life_control'             => $this->scoreVal($row['func_control'] ?? null),
                'd4_income_limits'            => $this->scoreVal($row['env_income_limit_r'] ?? null),
                'e1_social_support'           => $this->scoreVal($row['soc_social_support'] ?? null),
                'e2_close_person'             => $this->scoreVal($row['soc_close_friend'] ?? null),
                // Note: e3/e4 intentionally swap soc_participation ↔ soc_opportunity
                // (matches OscaCsvSeeder line 212-213 and export_normalized_db.py QOL_DB_MAP)
                'e3_community_opportunities'  => $this->scoreVal($row['soc_participation'] ?? null),
                'e4_participation'            => $this->scoreVal($row['soc_opportunity'] ?? null),
                'e5_respect'                  => $this->scoreVal($row['soc_respect'] ?? null),
                'f1_home_safety'              => $this->scoreVal($row['env_safe_home'] ?? null),
                'f2_neighborhood_safety'      => $this->scoreVal($row['env_safe_neighborhood'] ?? null),
                'f3_service_access'           => $this->scoreVal($row['env_service_access'] ?? null),
                'f4_home_comfort'             => $this->scoreVal($row['env_home_comfort'] ?? null),
                'g1_household_expenses'       => $this->scoreVal($row['env_fin_household'] ?? null),
                'g2_medical_afford'           => $this->scoreVal($row['env_fin_medical'] ?? null),
                'g3_personal_wants'           => $this->scoreVal($row['env_fin_personal'] ?? null),
                'h1_belief_comfort'           => $this->scoreVal($row['spi_belief_comfort'] ?? null),
                'h2_belief_practice'          => $this->scoreVal($row['spi_belief_practice'] ?? null),
                'status'                      => 'submitted',
            ]);

            $survey->computeScores();
            $inserted++;

            if ($inserted % 10 === 0) {
                $this->command->info("  ... inserted {$inserted} so far");
            }
        }

        fclose($fp);

        $this->command->info('');
        $this->command->info('=== MagdapioImportSeeder complete ===');
        $this->command->info("  Inserted (new):        {$inserted}");
        $this->command->info("  Skipped (duplicate):   {$skippedDup}");
        $this->command->info("  Skipped (missing req): {$skippedMissing}");
        $this->command->info('');
        $this->command->info('Next: php artisan ml:repair-notebook-cache --all');
    }

    // ── Helpers (identical to OscaCsvSeeder) ─────────────────────────────────

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

    private function parseDate($value, bool $dobMode = false): ?string
    {
        $v = $this->strVal($value);
        if ($v === null) {
            return null;
        }

        $hasSlashDate = preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $v, $m);

        if ($hasSlashDate) {
            $dateOnly     = "{$m[1]}/{$m[2]}/{$m[3]}";
            $hasTimeSuffix = (bool) preg_match('/\s+\d/', $v);

            if ((int) $m[1] > 12) {
                try {
                    return Carbon::createFromFormat('d/m/Y', $dateOnly)->format('Y-m-d');
                } catch (\Throwable $e) {
                }
            } elseif ($dobMode && $hasTimeSuffix) {
                try {
                    return Carbon::createFromFormat('d/m/Y', $dateOnly)->format('Y-m-d');
                } catch (\Throwable $e) {
                }
            }
        }

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
