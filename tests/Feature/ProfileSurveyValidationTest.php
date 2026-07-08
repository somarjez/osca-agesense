<?php

namespace Tests\Feature;

use App\Livewire\Surveys\ProfileSurvey;
use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 3 — input validation hardening for ProfileSurvey: the per-step rules
 * added in validateCurrentStep()/save(), the barangay whitelist, and the
 * closed "skip step navigation via direct save()" gap.
 */
class ProfileSurveyValidationTest extends TestCase
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
            ['email' => 'psvalid-admin@osca.local'],
            ['name' => 'PSValid Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);

        $this->actingAs($this->admin);
    }

    /** Fill the step-1 required fields so save() can reach later-step validation. */
    private function fillRequired($component)
    {
        return $component
            ->set('firstName', 'Maria')
            ->set('lastName', 'Santos')
            ->set('barangay', 'Anibong')
            ->set('dateOfBirth', '1948-05-02');
    }

    #[Test]
    public function out_of_whitelist_barangay_is_rejected(): void
    {
        // Per-step navigation (validateCurrentStep() -> step1Rules()) must
        // still enforce the strict barangay whitelist for a freshly-typed
        // value. (Full-record save() was relaxed for barangay the same way
        // as the 5 whitelist-backed multi-select fields — see
        // legacy_out_of_whitelist_barangay_on_existing_record_does_not_block_unrelated_edit
        // — so this asserts against nextStep() rather than save().)
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('barangay', 'Not A Real Barangay')
            ->call('nextStep')
            ->assertHasErrors(['barangay']);

        $this->assertDatabaseMissing('senior_citizens', ['first_name' => 'Maria', 'last_name' => 'Santos']);
    }

    #[Test]
    public function whitelisted_barangay_is_accepted(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);
    }

    #[Test]
    public function invalid_specialization_value_on_step_3_is_rejected(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('step', 3)
            ->set('specialization', ['Not A Real Specialization'])
            ->call('nextStep')
            ->assertHasErrors(['specialization.0']);
    }

    #[Test]
    public function invalid_child_financial_support_value_on_step_2_is_rejected(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('step', 2)
            ->set('childFinancialSupport', 'Maybe')
            ->call('nextStep')
            ->assertHasErrors(['childFinancialSupport']);
    }

    #[Test]
    public function invalid_medical_concern_value_on_step_6_is_rejected(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('step', 6)
            ->set('medicalConcern', ['Not A Real Condition'])
            ->call('nextStep')
            ->assertHasErrors(['medicalConcern.0']);
    }

    #[Test]
    public function fully_valid_submission_across_all_steps_still_succeeds(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('numChildren', 2)
            ->set('numWorkingChildren', 1)
            ->set('householdSize', 3)
            ->set('childFinancialSupport', 'Yes')
            ->set('spouseWorking', 'No')
            ->set('specialization', ['Farming'])
            ->set('communityService', ['Barangay Volunteer'])
            ->set('livingWith', ['Spouse'])
            ->set('householdCondition', ['Owned House'])
            ->set('incomeSource', ['Own pension'])
            ->set('realAssets', ['House'])
            ->set('movableAssets', ['Vehicle'])
            ->set('monthlyIncomeRange', 'Below 5,000')
            ->set('problemsNeeds', ['Limited problems encountered'])
            ->set('medicalConcern', ['Hypertension'])
            ->set('socialEmotionalConcern', ['Living in a healthy environment'])
            ->set('dentalConcern', ['Healthy Teeth'])
            ->set('opticalConcern', ['Healthy Eyes'])
            ->set('hearingConcern', ['Healthy Hearing'])
            ->set('healthcareDifficulty', ['Healthcare is accessible'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $senior = SeniorCitizen::where('first_name', 'Maria')->where('last_name', 'Santos')->firstOrFail();
        $this->assertSame(['Farming'], $senior->specialization);
        $this->assertSame(['Hypertension'], $senior->medical_concern);
    }

    #[Test]
    public function direct_save_skipping_step_navigation_rejects_invalid_later_step_data(): void
    {
        // Simulates a component driven straight to save() without ever
        // walking through nextStep() for steps 2-6 (e.g. a scripted client
        // call) — the invalid step-2 value must still be caught. (Uses
        // childFinancialSupport rather than one of the 5 whitelist-backed
        // multi-select fields relaxed on full-record save() below — see
        // legacy_out_of_whitelist_specialization_on_existing_record_does_not_block_unrelated_edit.)
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('childFinancialSupport', 'Maybe')
            ->call('save')
            ->assertHasErrors(['childFinancialSupport']);

        $this->assertDatabaseMissing('senior_citizens', ['first_name' => 'Maria', 'last_name' => 'Santos']);
    }

    private function makeSenior(array $overrides = []): SeniorCitizen
    {
        return SeniorCitizen::create(array_merge([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
        ], $overrides));
    }

    #[Test]
    public function legacy_out_of_whitelist_specialization_on_existing_record_does_not_block_unrelated_edit(): void
    {
        // BulkUploadController ingests specialization via toList() with zero
        // normalization against specializationOptions(), so an existing
        // record can carry a free-text value the current whitelist no
        // longer recognizes. Editing an unrelated field on that record must
        // still succeed instead of being locked out by the full-record
        // save() whitelist re-check.
        $senior = $this->makeSenior([
            'specialization' => ['Legacy Bulk Import Skill'],
        ]);

        Livewire::test(ProfileSurvey::class, ['seniorId' => $senior->id])
            ->assertSet('specialization', ['Legacy Bulk Import Skill'])
            ->set('contactNumber', '09171234567')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $senior->refresh();
        $this->assertSame('09171234567', $senior->contact_number);
        $this->assertSame(['Legacy Bulk Import Skill'], $senior->specialization);
    }

    #[Test]
    public function legacy_out_of_whitelist_barangay_on_existing_record_does_not_block_unrelated_edit(): void
    {
        // Both BulkUploadController::upload() and OscaCsvSeeder store
        // barangay as a raw, un-normalized string (OscaCsvSeeder falls back
        // to 'Unknown' for missing values), so an existing record can carry
        // a value the current barangayList() whitelist no longer recognizes.
        // Editing an unrelated field on that record must still succeed
        // instead of being locked out by the full-record save() whitelist
        // re-check — same bug pattern as specialization/etc above.
        $senior = $this->makeSenior([
            'barangay' => 'Unknown',
        ]);

        Livewire::test(ProfileSurvey::class, ['seniorId' => $senior->id])
            ->assertSet('barangay', 'Unknown')
            ->set('contactNumber', '09171234567')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $senior->refresh();
        $this->assertSame('09171234567', $senior->contact_number);
        $this->assertSame('Unknown', $senior->barangay);
    }

    #[Test]
    public function per_step_navigation_still_rejects_fresh_invalid_selection_on_whitelisted_field(): void
    {
        // The full-record save() safety net above was relaxed for the 5
        // whitelist-backed multi-select fields, but per-step navigation
        // (validateCurrentStep(), used while actively filling the form)
        // must still enforce the strict whitelist for a freshly-typed
        // selection.
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('step', 3)
            ->set('communityService', ['Not A Real Service'])
            ->call('nextStep')
            ->assertHasErrors(['communityService.0']);
    }
}
