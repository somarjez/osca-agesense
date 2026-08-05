<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\SeniorCitizen;
use App\Models\User;
use App\Support\SeniorDataVersion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * BulkUploadController::upload() suppresses SeniorCitizen/QolSurvey model
 * events during the per-row import loop (see the withoutEvents() comment
 * there) — confirmed root cause of a 504 on a 360-row import was ~9 serial DB
 * round-trips/row via ActivityLogObserver, SeniorDataVersionObserver, and
 * MlResultStalenessObserver. This covers that the behavior actually load-
 * bearing among those three (uuid generation, the cache-version bump) is
 * still correct once per import, and that per-row audit noise is replaced
 * with a single summary entry rather than silently dropped.
 */
class BulkUploadObserverSuppressionTest extends TestCase
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
            ['email' => 'bulk-suppression-admin@osca.local'],
            ['name' => 'Bulk Suppression Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);
    }

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
        string $barangay = 'Pagsanjan',
        string $dob = '01/15/1950',
    ): string {
        return implode(',', [
            $firstName, '', $lastName, '', $barangay, $dob, '',
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

    #[Test]
    public function every_imported_senior_still_gets_a_unique_uuid(): void
    {
        $csv = self::HEADER."\n"
            .$this->makeRow('SuppressUuid', 'First', dob: '01/15/1950')."\n"
            .$this->makeRow('SuppressUuid', 'Second', dob: '02/20/1951')."\n";

        $this->uploadCsv($csv);

        $first = SeniorCitizen::where('first_name', 'SuppressUuid')->where('last_name', 'First')->firstOrFail();
        $second = SeniorCitizen::where('first_name', 'SuppressUuid')->where('last_name', 'Second')->firstOrFail();

        $this->assertNotEmpty($first->uuid);
        $this->assertNotEmpty($second->uuid);
        $this->assertNotSame($first->uuid, $second->uuid);
        // Route-model-binding depends on this actually resolving.
        $this->actingAs($this->admin)->get(route('seniors.show', $first))->assertOk();
    }

    #[Test]
    public function senior_data_version_bumps_exactly_once_per_import_not_per_row(): void
    {
        SeniorDataVersion::bump();
        $before = SeniorDataVersion::current();
        sleep(1); // bump() stamps now()->timestamp — force a distinguishable tick.

        $csv = self::HEADER."\n"
            .$this->makeRow('SuppressVersion', 'First', dob: '01/15/1950')."\n"
            .$this->makeRow('SuppressVersion', 'Second', dob: '02/20/1951')."\n"
            .$this->makeRow('SuppressVersion', 'Third', dob: '03/25/1952')."\n";

        $this->uploadCsv($csv);

        $this->assertGreaterThan($before, SeniorDataVersion::current());
    }

    #[Test]
    public function import_writes_one_summary_activity_log_not_one_per_row(): void
    {
        $csv = self::HEADER."\n"
            .$this->makeRow('SuppressLog', 'First', dob: '01/15/1950')."\n"
            .$this->makeRow('SuppressLog', 'Second', dob: '02/20/1951')."\n";

        $this->uploadCsv($csv);

        $summaryLogs = ActivityLog::where('action', 'bulk_uploaded')->get();
        $this->assertCount(1, $summaryLogs);
        $this->assertStringContainsString('2 senior(s)', $summaryLogs->first()->description);

        $first = SeniorCitizen::where('first_name', 'SuppressLog')->where('last_name', 'First')->firstOrFail();
        $second = SeniorCitizen::where('first_name', 'SuppressLog')->where('last_name', 'Second')->firstOrFail();

        // No per-row "created" entries for the suppressed models.
        $this->assertSame(0, ActivityLog::where('action', 'created')
            ->where('subject_type', SeniorCitizen::class)
            ->whereIn('subject_id', [$first->id, $second->id])
            ->count());
    }

    #[Test]
    public function an_all_skipped_file_does_not_bump_version_or_log_a_summary(): void
    {
        SeniorCitizen::create([
            'osca_id' => SeniorCitizen::generateOscaId('Pagsanjan'),
            'first_name' => 'AlreadyThere',
            'last_name' => 'Existing',
            'barangay' => 'Pagsanjan',
            'date_of_birth' => '1950-01-15',
            'gender' => 'Male',
            'status' => 'active',
            'encoded_by' => 'Test',
        ]);

        SeniorDataVersion::bump();
        $before = SeniorDataVersion::current();

        $csv = self::HEADER."\n".$this->makeRow('AlreadyThere', 'Existing', dob: '01/15/1950')."\n";
        $this->uploadCsv($csv);

        $this->assertSame($before, SeniorDataVersion::current());
        $this->assertSame(0, ActivityLog::where('action', 'bulk_uploaded')->count());
    }
}
