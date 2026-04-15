<?php

namespace Database\Seeders;

use App\Models\SeniorCitizen;
use App\Models\QolSurvey;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OscaSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding OSCA demo data…');

        $barangays    = SeniorCitizen::barangayList();
        $genders      = ['Male', 'Female'];
        $marital      = ['Single', 'Married', 'Widowed', 'Separated'];
        $religions    = ['Roman Catholic', 'Iglesia ni Cristo', 'Aglipayan', 'Protestant / Evangelical'];
        $educations   = ['Elementary Level', 'Elementary Graduate', 'High School Level', 'High School Graduate', 'Vocational', 'College Graduate'];
        $incomeRanges = ['Below 1,000', '1,000 - 5,000', '5,000 - 10,000', '10,000 - 20,000', '20,000 - 30,000'];
        $incomeSrcs   = ['Own pension', 'Dependent on children/relatives', 'Own earnings/salary', 'Savings', 'Livestock/Farm'];
        $medConcerns  = ['Hypertension', 'Diabetes', 'Arthritis / Gout', 'Asthma', 'Stroke', 'Physically Healthy'];

        $firstNames = ['Juan','Maria','Jose','Ana','Pedro','Elena','Antonio','Rosa','Manuel','Carmen',
                       'Ricardo','Teresita','Roberto','Luz','Fernando','Corazon','Eduardo','Marites',
                       'Cesar','Maricel','Renato','Erlinda','Virgilio','Natividad','Danilo','Lourdes'];
        $lastNames  = ['Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Mendoza', 'Torres',
                       'Ramos', 'Flores', 'Gonzales', 'de Leon', 'Castro', 'Morales', 'Villanueva'];

        // Create 60 demo senior citizens
        for ($i = 0; $i < 60; $i++) {
            $age = rand(60, 92);
            $dob = now()->subYears($age)->subDays(rand(0, 364))->format('Y-m-d');
            $barangay = $barangays[array_rand($barangays)];
            $gender   = $genders[array_rand($genders)];

            // Bias toward realistic distributions
            $isVulnerable = $age > 75 || rand(0, 3) === 0;

            $senior = SeniorCitizen::create([
                'osca_id'                  => SeniorCitizen::generateOscaId($barangay),
                'first_name'               => $firstNames[array_rand($firstNames)],
                'middle_name'              => $lastNames[array_rand($lastNames)],
                'last_name'                => $lastNames[array_rand($lastNames)],
                'barangay'                 => $barangay,
                'date_of_birth'            => $dob,
                'gender'                   => $gender,
                'marital_status'           => $marital[array_rand($marital)],
                'religion'                 => $religions[array_rand($religions)],
                'place_of_birth'           => 'Pagsanjan, Laguna',
                'contact_number'           => '09' . rand(100000000, 999999999),
                'educational_attainment'   => $educations[array_rand($educations)],
                'monthly_income_range'     => $incomeRanges[array_rand($incomeRanges)],
                'num_children'             => rand(0, 8),
                'num_working_children'     => rand(0, 4),
                'household_size'           => rand(1, 7),
                'child_financial_support'  => ['Yes', 'No', 'Occasional'][array_rand(['Yes','No','Occasional'])],
                'spouse_working'           => ['Yes', 'No', 'Deceased', 'N/A'][array_rand(['Yes','No','Deceased','N/A'])],
                'income_source'            => array_rand(array_flip($incomeSrcs), rand(1, 3)),
                'real_assets'              => rand(0,1) ? ['House and Lot'] : ['No known assets'],
                'movable_assets'           => ['Mobile Phone', 'Appliances (Refrigerator / TV / Washing Machine)'],
                'living_with'              => $isVulnerable && rand(0,2) === 0 ? ['Alone'] : ['Spouse', 'Children'],
                'household_condition'      => $isVulnerable ? ['High cost of rent'] : ['House is owned'],
                'medical_concern'          => array_rand(array_flip($medConcerns), rand(1, 3)),
                'dental_concern'           => 'Needs dental care',
                'optical_concern'          => rand(0,1) ? 'Blurred vision' : 'Healthy Eyes',
                'hearing_concern'          => $age > 75 ? 'Partial hearing loss' : 'Healthy Hearing',
                'has_medical_checkup'      => (bool) rand(0, 1),
                'checkup_schedule'         => 'Yearly',
                'community_service'        => rand(0,1) ? ['Senior Citizen Association Member'] : [],
                'specialization'           => ['Farming', 'Cooking'],
                'problems_needs'           => $isVulnerable
                    ? ['Health Related Issues', 'High cost of medicines', 'Lack of income/resources']
                    : ['Limited problems encountered'],
                'status'                   => 'active',
                'encoded_by'               => 'Admin',
            ]);

            // Create QoL survey for 80% of seniors
            if (rand(0, 4) > 0) {
                $this->createQolSurvey($senior, $isVulnerable);
            }
        }

        $this->command->info('✅ Seeded 60 senior citizens with QoL surveys.');
    }

    private function createQolSurvey(SeniorCitizen $senior, bool $isVulnerable): void
    {
        // Vulnerable seniors score lower (1-3), healthy score higher (3-5)
        $lo = $isVulnerable ? [1, 3] : [3, 5];
        $hi = $isVulnerable ? [3, 5] : [1, 3];  // for reverse-scored items

        $r = fn($a, $b) => rand($a, $b);

        $survey = QolSurvey::create([
            'senior_citizen_id'          => $senior->id,
            'survey_date'                => now()->subDays(rand(0, 60))->format('Y-m-d'),
            'survey_version'             => 'v1',
            // A: Overall QoL
            'a1_enjoy_life'              => $r($lo[0], $lo[1]),
            'a2_life_satisfaction'       => $r($lo[0], $lo[1]),
            'a3_future_outlook'          => $r($lo[0], $lo[1]),
            'a4_meaningfulness'          => $r($lo[0], $lo[1]),
            // B: Physical
            'b1_physical_energy'         => $r($lo[0], $lo[1]),
            'b2_pain_discomfort'         => $r($hi[0], $hi[1]),  // reverse
            'b3_health_self_care'        => $r($hi[0], $hi[1]),  // reverse
            'b4_health_outside'          => $r($lo[0], $lo[1]),
            'b5_mobility'                => $r($lo[0], $lo[1]),
            // C: Psychological
            'c1_happiness'               => $r($lo[0], $lo[1]),
            'c2_calm_peace'              => $r($lo[0], $lo[1]),
            'c3_loneliness'              => $r($hi[0], $hi[1]),  // reverse
            'c4_confidence'              => $r($lo[0], $lo[1]),
            // D: Independence
            'd1_independence'            => $r($lo[0], $lo[1]),
            'd2_time_control'            => $r($lo[0], $lo[1]),
            'd3_life_control'            => $r($lo[0], $lo[1]),
            'd4_income_limits'           => $r($hi[0], $hi[1]),  // reverse
            // E: Social
            'e1_social_support'          => $r($lo[0], $lo[1]),
            'e2_close_person'            => $r($lo[0], $lo[1]),
            'e3_community_opportunities' => $r($lo[0], $lo[1]),
            'e4_participation'           => $r($lo[0], $lo[1]),
            'e5_respect'                 => $r($lo[0], $lo[1]),
            // F: Home & Neighborhood
            'f1_home_safety'             => $r($lo[0], $lo[1]),
            'f2_neighborhood_safety'     => $r($lo[0], $lo[1]),
            'f3_service_access'          => $r($lo[0], $lo[1]),
            'f4_home_comfort'            => $r($lo[0], $lo[1]),
            // G: Financial
            'g1_household_expenses'      => $r($lo[0], $lo[1]),
            'g2_medical_afford'          => $r($lo[0], $lo[1]),
            'g3_personal_wants'          => $r($lo[0], $lo[1]),
            // H: Spirituality
            'h1_belief_comfort'          => $r(3, 5),
            'h2_belief_practice'         => $r(3, 5),
            'status'                     => 'submitted',
        ]);

        $survey->computeScores();
    }
}
