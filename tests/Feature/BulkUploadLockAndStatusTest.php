<?php

namespace Tests\Feature;

use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BulkUploadLockAndStatusTest extends TestCase
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
        string $firstName = 'LockFirst',
        string $lastName = 'LockLast',
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

    // ── Tests: double-submit lock ───────────────────────────────────────────

    #[Test]
    public function second_upload_is_rejected_while_a_lock_is_held_for_the_same_user(): void
    {
        // Manually acquire the exact same lock the controller uses, as the
        // authenticated admin, simulating "an import is already in flight".
        $lock = Cache::lock('bulk-import:'.$this->admin->id, 120);
        $this->assertTrue($lock->get(), 'Test setup should be able to acquire the lock.');

        $response = $this->uploadCsv($this->makeCsv(
            firstName: 'LockedOutFirst',
            lastName: 'LockedOutLast',
        ));

        $response->assertRedirect();
        $response->assertSessionHasErrors('file');
        $errors = session('errors');
        $this->assertStringContainsString(
            'Another import is already in progress',
            $errors->first('file')
        );

        // Zero rows from the rejected file were inserted.
        $this->assertSame(
            0,
            SeniorCitizen::where('first_name', 'LockedOutFirst')
                ->where('last_name', 'LockedOutLast')
                ->count(),
            'A request rejected by the lock must not insert any rows.'
        );

        $lock->release();

        // Once released, the exact same upload succeeds normally.
        $retryResponse = $this->uploadCsv($this->makeCsv(
            firstName: 'LockedOutFirst',
            lastName: 'LockedOutLast',
        ));

        $retryResponse->assertSessionHasNoErrors();
        $this->assertSame(
            1,
            SeniorCitizen::where('first_name', 'LockedOutFirst')
                ->where('last_name', 'LockedOutLast')
                ->count(),
            'The retried upload after lock release should insert the row normally.'
        );
    }

    #[Test]
    public function a_normal_upload_releases_its_own_lock_on_success(): void
    {
        $response = $this->uploadCsv($this->makeCsv(
            firstName: 'ReleaseFirst',
            lastName: 'ReleaseLast',
        ));

        $response->assertSessionHasNoErrors();

        // The controller's own lock must have been released once the request
        // finished — a fresh attempt to acquire it from outside must succeed.
        $lock = Cache::lock('bulk-import:'.$this->admin->id, 120);
        $this->assertTrue(
            $lock->get(),
            'The lock must be released once a successful upload completes.'
        );
        $lock->release();
    }

    // ── Tests: status endpoint ───────────────────────────────────────────────

    #[Test]
    public function status_endpoint_reports_idle_when_no_import_has_run(): void
    {
        $response = $this->actingAs($this->admin)->getJson(route('seniors.bulk-upload.status'));

        $response->assertOk();
        $response->assertJson(['status' => 'idle']);
    }

    #[Test]
    public function status_endpoint_reflects_a_completed_import(): void
    {
        $uploadResponse = $this->uploadCsv($this->makeCsv(
            firstName: 'StatusFirst',
            lastName: 'StatusLast',
        ));
        $uploadResponse->assertSessionHasNoErrors();

        $statusResponse = $this->actingAs($this->admin)->getJson(route('seniors.bulk-upload.status'));

        $statusResponse->assertOk();
        $statusResponse->assertJson([
            'status' => 'done',
            'total' => 1,
            'inserted' => 1,
            'skipped' => 0,
        ]);
    }
}
