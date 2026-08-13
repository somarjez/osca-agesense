<?php

namespace Tests\Feature;

use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for the audit's TC-REC-02 / TC-IMP-03 findings: bulk
 * import used to apply almost no validation — an invalid name ("Pedro#"), a
 * future or calendar-invalid date of birth, and an out-of-range/non-numeric
 * QoL score all persisted verbatim or got silently coerced into fabricated
 * data. See BulkUploadController's row-processing loop and scoreVal().
 */
class BulkUploadValidationTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::firstOrCreate(
            ['email' => 'bulkvalid-admin@osca.local'],
            ['name' => 'BulkValid Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);
    }

    // ── Helpers (same shape as BulkUploadDuplicateTest's) ──────────────────────

    private function makeCsv(
        string $firstName = 'ValidFirst',
        string $lastName = 'ValidLast',
        string $barangay = 'Pagsanjan',
        string $dob = '01/15/1950',
        string $gender = 'Male',
        string $qol1 = '3',
        string $qol2 = '3',
    ): string {
        $header = 'first_name,middle_name,last_name,name_ext,barangay,dob,contact_number,'.
                  'place_of_birth,marital_status,gender,religion,ethnic_origin,blood_type,'.
                  'num_children,num_working_children,child_financial_support,spouse_working,'.
                  'household_size,education,specialization,community_service,living_with,'.
                  'household_condition,income_source,real_assets,movable_assets,'.
                  'monthly_income_range,problems_needs,medical_concern,dental_concern,'.
                  'optical_concern,hearing_concern,social_emotional_concern,healthcare_difficulty,'.
                  'has_medical_checkup,checkup_schedule,qol_enjoy_life,qol_life_satisfaction,'.
                  'qol_future_outlook,qol_meaningfulness,phy_energy,phy_pain_r,'.
                  'phy_health_limit_r,phy_mobility_outside,phy_mobility_indoor,psych_happiness,'.
                  'psych_peace,psych_lonely_r,psych_confidence,func_independence,func_autonomy,'.
                  'func_control,env_income_limit_r,soc_social_support,soc_close_friend,'.
                  'soc_participation,soc_opportunity,soc_respect,env_safe_home,'.
                  'env_safe_neighborhood,env_service_access,env_home_comfort,env_fin_household,'.
                  'env_fin_medical,env_fin_personal,spi_belief_comfort,spi_belief_practice,timestamp';

        $data = implode(',', [
            $firstName, '', $lastName, '', $barangay, $dob,
            '', '', 'Single', $gender, '', '', '', '0', '0', 'N/A', 'N/A', '1',
            // NOTE: unlike BulkUploadDuplicateTest's copy of this helper, this
            // value is deliberately comma-free ("Below 5000" not "Below
            // 5,000") — the CSV is built with a plain implode(',', ...), no
            // quoting/escaping, so an embedded comma here would silently
            // shift every column after it by one, corrupting the qol1/qol2
            // assertions this test file actually checks.
            '', '', '', '', '', '', '', '', 'Below 5000', '', '', '', '', '', '', '',
            'No', '',
            $qol1, $qol2, '3', '3', '3', '3', '3', '3', '3',
            '3', '3', '3', '3', '3', '3', '3', '3',
            '3', '3', '3', '3', '3', '3', '3', '3', '3',
            '3', '3', '3', '3', '3', '3', '3', '3',
            '01/10/2024',
        ]);

        return $header."\n".$data."\n";
    }

    private function uploadCsv(string $csvContent): TestResponse
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'test_').'.csv';
        file_put_contents($tmpPath, $csvContent);

        $file = new UploadedFile($tmpPath, 'test.csv', 'text/csv', null, true);

        return $this->actingAs($this->admin)
            ->post(route('seniors.bulk-upload'), ['file' => $file]);
    }

    // ── Name validation (TC-REC-02) ─────────────────────────────────────────────

    #[Test]
    public function row_with_invalid_first_name_characters_is_skipped_not_inserted(): void
    {
        $response = $this->uploadCsv($this->makeCsv(firstName: 'Pedro#'));

        $this->assertDatabaseMissing('senior_citizens', ['first_name' => 'Pedro#']);
        $errors = session('bulk_errors') ?? [];
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('invalid name', implode(' ', $errors));
    }

    #[Test]
    public function row_with_valid_name_characters_is_inserted(): void
    {
        $firstName = 'Maria-Jose';
        $this->uploadCsv($this->makeCsv(firstName: $firstName, lastName: "O'Brien"));

        $this->assertDatabaseHas('senior_citizens', ['first_name' => $firstName, 'last_name' => "O'Brien"]);
    }

    // ── DOB validation (TC-IMP-03) ──────────────────────────────────────────────

    #[Test]
    public function future_date_of_birth_is_skipped_not_inserted(): void
    {
        $firstName = 'FutureDob';
        $response = $this->uploadCsv($this->makeCsv(firstName: $firstName, dob: '01/01/2099'));

        $this->assertDatabaseMissing('senior_citizens', ['first_name' => $firstName]);
        $errors = session('bulk_errors') ?? [];
        $this->assertStringContainsString('future', implode(' ', $errors));
    }

    #[Test]
    public function calendar_invalid_date_of_birth_is_skipped_not_silently_rolled_forward(): void
    {
        // Feb 31 doesn't exist — this used to silently become Mar 2 instead
        // of being rejected (TC-IMP-03's "6-year-old senior" finding).
        $firstName = 'BadCalendarDob';
        $response = $this->uploadCsv($this->makeCsv(firstName: $firstName, dob: '02/31/2020'));

        $this->assertDatabaseMissing('senior_citizens', ['first_name' => $firstName]);
        // Must NOT have silently rolled forward to March.
        $this->assertDatabaseMissing('senior_citizens', ['first_name' => $firstName, 'date_of_birth' => '2020-03-02']);
    }

    // ── QoL score validation (TC-IMP-03) ────────────────────────────────────────

    #[Test]
    public function out_of_range_and_non_numeric_qol_scores_are_rejected_not_remapped(): void
    {
        $firstName = 'BadScoreQA';
        $this->uploadCsv($this->makeCsv(firstName: $firstName, qol1: '9', qol2: 'abc'));

        $senior = SeniorCitizen::where('first_name', $firstName)->first();
        $this->assertNotNull($senior, 'Row should still be inserted — QoL scores are optional.');

        $survey = QolSurvey::where('senior_citizen_id', $senior->id)->first();
        $this->assertNotNull($survey);
        $this->assertNull($survey->a1_enjoy_life, 'Out-of-range "9" must be rejected to null, not clamped to 5.');
        $this->assertNull($survey->a2_life_satisfaction, 'Non-numeric "abc" must be rejected to null, not remapped to a number.');
    }

    #[Test]
    public function near_miss_qol_scores_are_still_clamped(): void
    {
        $firstName = 'NearMissScoreQA';
        $this->uploadCsv($this->makeCsv(firstName: $firstName, qol1: '0', qol2: '6'));

        $senior = SeniorCitizen::where('first_name', $firstName)->first();
        $survey = QolSurvey::where('senior_citizen_id', $senior->id)->first();
        $this->assertSame(1, $survey->a1_enjoy_life, '"0" is a plausible typo of "1" — should clamp, not reject.');
        $this->assertSame(5, $survey->a2_life_satisfaction, '"6" is a plausible typo of "5" — should clamp, not reject.');
    }
}
