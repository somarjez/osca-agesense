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

/**
 * Covers the optional admin-supplied OSCA-ID column in bulk upload, distinct
 * from the system-generated osca_id (System ID) that upload() already
 * assigns to every imported row via generateOscaId().
 */
class BulkUploadOfficialOscaIdTest extends TestCase
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
            ['email' => 'bulk-osca-admin@osca.local'],
            ['name' => 'Bulk OSCA Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private const HEADER = 'first_name,middle_name,last_name,name_ext,barangay,dob,osca_id,contact_number,'.
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

    private function makeRow(
        string $firstName,
        string $lastName,
        string $oscaId = '',
        string $barangay = 'Pagsanjan',
        string $dob = '01/15/1950',
    ): string {
        return implode(',', [
            $firstName, '', $lastName, '', $barangay, $dob, $oscaId,
            '', 'Single', 'Male', '', '', '', '0', '0', 'N/A', 'N/A', '1',
            '', '', '', '', '', '', '', '', 'Below 5,000', '', '', '', '', '', '', '',
            'No', '',
            '3', '3', '3', '3', '3', '3', '3', '3', '3',
            '3', '3', '3', '3', '3', '3', '3', '3',
            '3', '3', '3', '3', '3', '3', '3', '3', '3',
            '3', '3', '3', '3', '3', '3', '3', '3',
            '01/10/2024',
        ]);
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
    public function osca_id_column_is_saved_when_provided(): void
    {
        $csv = self::HEADER."\n".$this->makeRow('BulkOsca', 'HasId', oscaId: '55-5555-555')."\n";

        $this->uploadCsv($csv)->assertRedirect(route('seniors.index'));

        $senior = SeniorCitizen::where('first_name', 'BulkOsca')->where('last_name', 'HasId')->firstOrFail();
        $this->assertSame('55-5555-555', $senior->official_osca_id);
    }

    #[Test]
    public function blank_osca_id_column_saves_as_unassigned(): void
    {
        $csv = self::HEADER."\n".$this->makeRow('BulkOsca', 'NoId', oscaId: '')."\n";

        $this->uploadCsv($csv)->assertRedirect(route('seniors.index'));

        $senior = SeniorCitizen::where('first_name', 'BulkOsca')->where('last_name', 'NoId')->firstOrFail();
        $this->assertNull($senior->official_osca_id);
        $this->assertSame('Unassigned', $senior->official_osca_id_display);
    }

    #[Test]
    public function duplicate_osca_id_against_existing_senior_is_imported_without_it(): void
    {
        SeniorCitizen::create([
            'osca_id' => 'TST-EXIST-'.uniqid(),
            'official_osca_id' => '77-7777-777',
            'first_name' => 'Existing',
            'last_name' => 'Holder',
            'barangay' => 'Pagsanjan',
            'date_of_birth' => '1945-01-01',
            'gender' => 'Male',
            'status' => 'active',
            'encoded_by' => 'Test',
        ]);

        $csv = self::HEADER."\n".$this->makeRow('BulkOsca', 'Dupe', oscaId: '77-7777-777')."\n";

        $response = $this->uploadCsv($csv);
        $response->assertRedirect(route('seniors.index'));
        $response->assertSessionHas('bulk_success');
        $this->assertStringContainsString('1 senior', session('bulk_success'));

        $senior = SeniorCitizen::where('first_name', 'BulkOsca')->where('last_name', 'Dupe')->firstOrFail();
        $this->assertNull($senior->official_osca_id, 'Duplicate OSCA ID should not have been assigned.');

        $errors = session('bulk_errors') ?? [];
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('77-7777-777', implode(' ', $errors));
    }

    #[Test]
    public function duplicate_osca_id_against_an_archived_senior_is_imported_without_it_not_aborted(): void
    {
        $archived = SeniorCitizen::create([
            'osca_id' => 'TST-ARCHIVED-'.uniqid(),
            'official_osca_id' => '66-6666-666',
            'first_name' => 'Archived',
            'last_name' => 'Holder',
            'barangay' => 'Pagsanjan',
            'date_of_birth' => '1940-01-01',
            'gender' => 'Male',
            'status' => 'active',
            'encoded_by' => 'Test',
        ]);
        $archived->delete();
        $this->assertTrue($archived->trashed());

        $csv = self::HEADER."\n".$this->makeRow('BulkOsca', 'VsArchived', oscaId: '66-6666-666')."\n";

        $response = $this->uploadCsv($csv);
        $response->assertRedirect(route('seniors.index'));
        $response->assertSessionHas('bulk_success');
        $this->assertStringContainsString('1 senior', session('bulk_success'));

        $senior = SeniorCitizen::where('first_name', 'BulkOsca')->where('last_name', 'VsArchived')->firstOrFail();
        $this->assertNull($senior->official_osca_id, 'Collision with an archived senior\'s OSCA ID should not have been assigned.');

        $errors = session('bulk_errors') ?? [];
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('66-6666-666', implode(' ', $errors));
    }

    #[Test]
    public function duplicate_osca_id_within_the_same_file_is_kept_on_first_row_only(): void
    {
        $csv = self::HEADER."\n"
            .$this->makeRow('BulkOsca', 'First', oscaId: '88-8888-888', dob: '01/15/1950')."\n"
            .$this->makeRow('BulkOsca', 'Second', oscaId: '88-8888-888', dob: '02/20/1951')."\n";

        $response = $this->uploadCsv($csv);
        $response->assertRedirect(route('seniors.index'));

        $first = SeniorCitizen::where('first_name', 'BulkOsca')->where('last_name', 'First')->firstOrFail();
        $second = SeniorCitizen::where('first_name', 'BulkOsca')->where('last_name', 'Second')->firstOrFail();

        $this->assertSame('88-8888-888', $first->official_osca_id);
        $this->assertNull($second->official_osca_id);
    }
}
