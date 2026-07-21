<?php

namespace App\Console\Commands;

use App\Support\SeniorDataVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * osca:seed-loadtest
 *
 * Bulk-inserts synthetic seniors + qol_surveys + ml_results + recommendations
 * DIRECTLY into the database (bypassing Eloquent events and the Python ML
 * pipeline) so the app can be profiled at 7k-10k records without running
 * real inference 10,000 times. Existing data is left untouched; synthetic
 * rows are tagged `encoded_by = 'LOAD_TEST_SEED'` on senior_citizens so they
 * can be found and removed independently of real records.
 *
 * This is a perf-testing tool, not a fixture for correctness tests: score/
 * risk/cluster values are randomized-but-plausible, not run through the real
 * ML model. Never point it at a production database.
 *
 * Usage:
 *   php artisan osca:seed-loadtest --count=10000
 *   php artisan osca:seed-loadtest --fresh              (remove synthetic rows only)
 *   php artisan osca:seed-loadtest --count=10000 --force (skip confirmation prompt)
 *
 * Assumes exclusive DB access while running (single writer) — it infers
 * newly-inserted senior IDs from MAX(id) rather than per-row insertGetId()
 * for bulk-insert speed.
 */
class SeedLoadTest extends Command
{
    protected $signature = 'osca:seed-loadtest
                            {--count=10000 : Number of synthetic seniors to insert}
                            {--fresh       : Remove previously-seeded synthetic rows (run alone, or before --count to reset first)}
                            {--force       : Skip the confirmation prompt}';

    protected $description = 'Bulk-insert synthetic seniors for load/perf testing at 7k-10k scale. Tagged for easy removal.';

    private const TAG = 'LOAD_TEST_SEED';

    private const CHUNK = 500;

    private const CLUSTERS = [
        1 => 'Resilient Independent',
        2 => 'Moderate Support Needed',
        3 => 'Vulnerable High-Need',
        4 => 'Critical Multi-Domain',
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run osca:seed-loadtest in the production environment.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->removeSyntheticData();

            if (! $this->option('count') || (int) $this->option('count') === 0) {
                return self::SUCCESS;
            }
        }

        $count = max(1, (int) $this->option('count'));

        if (! $this->option('force') && ! $this->confirm(
            "This will insert {$count} synthetic seniors (+ surveys + ML results) tagged '".self::TAG."' into the CURRENT database. Continue?",
            true
        )) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $barangays = DB::table('senior_citizens')->distinct()->pluck('barangay')->filter()->values()->all();
        if (empty($barangays)) {
            $barangays = [
                'Anibong', 'Barangay I (Poblacion)', 'Barangay II (Poblacion)', 'Biñan', 'Buboy',
                'Cabanbanan', 'Calusiche', 'Dingin', 'Layugan', 'Magdapio', 'Maulawin',
                'Pinagsanjan', 'Sabang', 'Sampaloc', 'San Isidro',
            ];
        }

        $genders = ['Male', 'Female'];
        $maritals = ['Single', 'Married', 'Widowed', 'Separated'];
        $incomeRanges = ['Below 1,000', '1,000-3,000', '3,001-5,000', '5,001-10,000', '10,001-20,000', '20,000 and above'];

        $this->info("Seeding {$count} synthetic seniors in chunks of ".self::CHUNK.'...');
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $runTag = 'LDT'.substr((string) time(), -6);
        $seq = 0;
        $remaining = $count;

        while ($remaining > 0) {
            $n = min(self::CHUNK, $remaining);

            $seniorRows = [];
            $now = now();
            for ($i = 0; $i < $n; $i++) {
                $seq++;
                $barangay = $barangays[array_rand($barangays)];
                $ageYears = random_int(60, 95);
                $dob = $now->copy()->subYears($ageYears)->subDays(random_int(0, 364));

                $seniorRows[] = [
                    'uuid' => (string) Str::uuid(),
                    'osca_id' => $runTag.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
                    'official_osca_id' => null,
                    'first_name' => 'LoadTest',
                    'middle_name' => null,
                    'last_name' => 'Senior'.$seq,
                    'name_extension' => null,
                    'barangay' => $barangay,
                    'date_of_birth' => $dob->toDateString(),
                    'age' => $ageYears,
                    'contact_number' => null,
                    'place_of_birth' => null,
                    'marital_status' => $maritals[array_rand($maritals)],
                    'gender' => $genders[array_rand($genders)],
                    'religion' => null,
                    'ethnic_origin' => null,
                    'blood_type' => null,
                    'philsys_id' => null,
                    'num_children' => random_int(0, 6),
                    'num_working_children' => random_int(0, 3),
                    'child_financial_support' => 'N/A',
                    'spouse_working' => 'N/A',
                    'household_size' => random_int(1, 8),
                    'educational_attainment' => 'Elementary',
                    'specialization' => '[]',
                    'community_service' => '[]',
                    'living_with' => '[]',
                    'household_condition' => '[]',
                    'income_source' => '[]',
                    'real_assets' => '[]',
                    'movable_assets' => '[]',
                    'monthly_income_range' => $incomeRanges[array_rand($incomeRanges)],
                    'problems_needs' => '[]',
                    'medical_concern' => '[]',
                    'dental_concern' => '[]',
                    'optical_concern' => '[]',
                    'hearing_concern' => '[]',
                    'social_emotional_concern' => '[]',
                    'healthcare_difficulty' => '[]',
                    'has_medical_checkup' => 0,
                    'checkup_schedule' => null,
                    'latitude' => null,
                    'longitude' => null,
                    'location_source' => null,
                    'location_accuracy' => null,
                    'location_verified_at' => null,
                    'status' => 'active',
                    'encoded_by' => self::TAG,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('senior_citizens')->insert($seniorRows);

            // Look IDs up by our own unique osca_id tag rather than assuming a contiguous
            // MAX(id)+1 range — AUTO_INCREMENT does not roll back after a DELETE (e.g. from
            // a prior --fresh cleanup), so id gaps are expected and arithmetic on MAX(id)
            // silently produces wrong foreign keys.
            $seniorIds = DB::table('senior_citizens')
                ->whereIn('osca_id', array_column($seniorRows, 'osca_id'))
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $surveyRows = [];
            foreach ($seniorIds as $sid) {
                $overall = round(mt_rand(100, 500) / 100, 3); // 1.00 - 5.00
                $scoreFields = [
                    'a1_enjoy_life', 'a2_life_satisfaction', 'a3_future_outlook', 'a4_meaningfulness',
                    'b1_physical_energy', 'b2_pain_discomfort', 'b3_health_self_care', 'b4_health_outside', 'b5_mobility',
                    'c1_happiness', 'c2_calm_peace', 'c3_loneliness', 'c4_confidence',
                    'd1_independence', 'd2_time_control', 'd3_life_control', 'd4_income_limits',
                    'e1_social_support', 'e2_close_person', 'e3_community_opportunities', 'e4_participation', 'e5_respect',
                    'f1_home_safety', 'f2_neighborhood_safety', 'f3_service_access', 'f4_home_comfort',
                    'g1_household_expenses', 'g2_medical_afford', 'g3_personal_wants',
                    'h1_belief_comfort', 'h2_belief_practice',
                ];
                $scoreValues = [];
                foreach ($scoreFields as $field) {
                    $scoreValues[$field] = random_int(1, 5);
                }

                $surveyRows[] = array_merge(
                    ['senior_citizen_id' => $sid, 'survey_version' => 'v1', 'step' => 5,
                        'survey_date' => $now->toDateString(), 'status' => 'processed',
                        'created_at' => $now, 'updated_at' => $now],
                    $scoreValues,
                    [
                        'score_qol' => $overall, 'score_physical' => $overall, 'score_psychological' => $overall,
                        'score_independence' => $overall, 'score_social' => $overall, 'score_environment' => $overall,
                        'score_financial' => $overall, 'score_spirituality' => $overall, 'overall_score' => $overall,
                    ]
                );
            }
            DB::table('qol_surveys')->insert($surveyRows);

            // One survey per senior in this chunk, so senior_citizen_id is a safe key
            // (avoids the same AUTO_INCREMENT-gap pitfall as the senior_citizens lookup above).
            $surveyIdBySenior = DB::table('qol_surveys')
                ->whereIn('senior_citizen_id', $seniorIds)
                ->pluck('id', 'senior_citizen_id');

            $mlRows = [];
            foreach ($seniorIds as $sid) {
                $composite = round(mt_rand(0, 10000) / 10000, 4);
                $riskLevel = $composite >= 0.70 ? 'HIGH' : ($composite >= 0.40 ? 'MODERATE' : 'LOW');
                $clusterId = random_int(1, 4);

                $mlRows[] = [
                    'senior_citizen_id' => $sid,
                    'qol_survey_id' => $surveyIdBySenior[$sid],
                    'model_version' => 'v1',
                    'prediction_source' => 'live_model',
                    'is_cached_prediction' => 0,
                    'is_stale' => 0,
                    'critical_flag' => 0,
                    'cluster_id' => $clusterId,
                    'cluster_named_id' => $clusterId,
                    'cluster_name' => self::CLUSTERS[$clusterId],
                    'ic_risk' => round(mt_rand(0, 10000) / 10000, 4),
                    'env_risk' => round(mt_rand(0, 10000) / 10000, 4),
                    'func_risk' => round(mt_rand(0, 10000) / 10000, 4),
                    'composite_risk' => $composite,
                    'wellbeing_score' => round(mt_rand(0, 10000) / 10000, 4),
                    'risk_medical' => round(mt_rand(0, 10000) / 10000, 4),
                    'risk_functional' => round(mt_rand(0, 10000) / 10000, 4),
                    'overall_risk_level' => $riskLevel,
                    'priority_flag' => $composite >= 0.70 ? 'urgent' : null,
                    'processed_at' => $now,
                    'scored_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('ml_results')->insert($mlRows);

            // One ml_result per senior in this chunk — same senior_citizen_id-keyed lookup.
            $mlIdBySenior = DB::table('ml_results')
                ->whereIn('senior_citizen_id', $seniorIds)
                ->pluck('id', 'senior_citizen_id');

            $recRows = [];
            foreach ($seniorIds as $sid) {
                $recCount = random_int(1, 3);
                for ($r = 0; $r < $recCount; $r++) {
                    $recRows[] = [
                        'ml_result_id' => $mlIdBySenior[$sid],
                        'senior_citizen_id' => $sid,
                        'priority' => $r + 1,
                        'type' => 'general',
                        'domain' => 'General',
                        'category' => 'Load Test',
                        'action' => 'Synthetic load-test recommendation #'.($r + 1),
                        'requires_human_validation' => 1,
                        'urgency' => 'planned',
                        'risk_level' => 'low',
                        'status' => 'pending',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            if (! empty($recRows)) {
                DB::table('recommendations')->insert($recRows);
            }

            $remaining -= $n;
            $bar->advance($n);
        }

        $bar->finish();
        $this->newLine();

        SeniorDataVersion::bump();

        $this->info('Done. Synthetic seniors tagged encoded_by="'.self::TAG.'". Run --fresh to remove them.');

        return self::SUCCESS;
    }

    private function removeSyntheticData(): void
    {
        $ids = DB::table('senior_citizens')->where('encoded_by', self::TAG)->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('No synthetic load-test rows found.');

            return;
        }

        $this->info("Removing {$ids->count()} synthetic seniors and their surveys/ML results/recommendations...");

        foreach ($ids->chunk(500) as $chunk) {
            $chunkIds = $chunk->all();
            DB::table('recommendations')->whereIn('senior_citizen_id', $chunkIds)->delete();
            DB::table('ml_results')->whereIn('senior_citizen_id', $chunkIds)->delete();
            DB::table('qol_surveys')->whereIn('senior_citizen_id', $chunkIds)->delete();
            DB::table('senior_citizens')->whereIn('id', $chunkIds)->delete();
        }

        SeniorDataVersion::bump();
        $this->info('Synthetic data removed.');
    }
}
