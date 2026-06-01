<?php

namespace Tests\Feature;

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

class BulkUploadDuplicateTest extends TestCase
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
            ['email' => 'admin@osca.local'],
            ['name' => 'OSCA Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Build a minimal valid CSV string for one senior row. */
    private function makeCsv(
        string $firstName = 'DupeFirst',
        string $lastName = 'DupeLast',
        string $barangay = 'Pagsanjan',
        string $dob = '01/15/1950',
        string $gender = 'Male',
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
            '', '', '', '', '', '', '', '', 'Below 5,000', '', '', '', '', '', '', '',
            'No', '',
            '3', '3', '3', '3', '3', '3', '3', '3', '3',
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

    // ── Tests ─────────────────────────────────────────────────────────────────

    #[Test]
    public function duplicate_senior_is_skipped_on_upload(): void
    {
        // Create an active senior with the same demographics
        SeniorCitizen::create([
            'osca_id' => 'TST-DUPE-'.uniqid(),
            'first_name' => 'DupeFirst',
            'last_name' => 'DupeLast',
            'barangay' => 'Pagsanjan',
            'date_of_birth' => '1950-01-15',
            'gender' => 'Male',
            'status' => 'active',
            'encoded_by' => 'Test',
        ]);

        $countBefore = SeniorCitizen::where('first_name', 'DupeFirst')
            ->where('last_name', 'DupeLast')
            ->count();

        $response = $this->uploadCsv($this->makeCsv());

        $response->assertRedirect(route('seniors.index'));

        // No new record should have been inserted
        $countAfter = SeniorCitizen::where('first_name', 'DupeFirst')
            ->where('last_name', 'DupeLast')
            ->count();
        $this->assertSame($countBefore, $countAfter, 'Duplicate senior should not be inserted.');

        // Flash messages should indicate a skipped row
        $response->assertSessionHas('bulk_errors');
        $errors = session('bulk_errors') ?? [];
        $this->assertNotEmpty($errors, 'bulk_errors flash should contain the skipped row message.');
        $this->assertStringContainsString('already exists', $errors[0]);
    }

    #[Test]
    public function unique_senior_is_inserted_on_upload(): void
    {
        $response = $this->uploadCsv($this->makeCsv(
            firstName: 'UniqueFirst'.uniqid(),
            lastName: 'UniqueLast',
            dob: '07/20/1952',
        ));

        $response->assertRedirect(route('seniors.index'));
        $response->assertSessionHas('bulk_success');
        $this->assertStringContainsString('1 senior', session('bulk_success'));
    }
}
