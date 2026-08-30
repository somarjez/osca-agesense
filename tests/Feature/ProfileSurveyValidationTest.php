<?php

namespace Tests\Feature;

use App\Livewire\Surveys\ProfileSurvey;
use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** Fill every required field so tests can isolate one validation behavior. */
    private function fillRequired($component)
    {
        return $component
            ->set('firstName', 'Maria')
            ->set('lastName', 'Santos')
            ->set('barangay', 'Anibong')
            ->set('dateOfBirth', '1948-05-02')
            ->set('gender', 'Female')
            ->set('maritalStatus', 'Widowed')
            // Non-zero so the default childFinancialSupport of 'Yes' below
            // doesn't trip the "no support without children" contradiction
            // rule (step2Rules()) for every test using this baseline.
            ->set('numChildren', 2)
            ->set('childFinancialSupport', 'Yes')
            ->set('spouseWorking', 'N/A')
            ->set('educationalAttainment', 'High School Graduate')
            ->set('livingWith', ['Children'])
            ->set('monthlyIncomeRange', '5,000 - 10,000');
    }

    public static function newlyRequiredAnswerFields(): array
    {
        return [
            'gender' => ['gender', ''],
            'marital status' => ['maritalStatus', ''],
            'financial support' => ['childFinancialSupport', ''],
            'spouse employment' => ['spouseWorking', ''],
            'educational attainment' => ['educationalAttainment', ''],
            'living arrangement' => ['livingWith', []],
            'monthly income' => ['monthlyIncomeRange', ''],
            'real assets' => ['realAssets', []],
            'movable assets' => ['movableAssets', []],
            'problems and needs' => ['problemsNeeds', []],
            'medical concern' => ['medicalConcern', []],
            'social and emotional concern' => ['socialEmotionalConcern', []],
            'dental concern' => ['dentalConcern', []],
            'optical concern' => ['opticalConcern', []],
            'hearing concern' => ['hearingConcern', []],
            'healthcare access' => ['healthcareDifficulty', []],
        ];
    }

    #[Test]
    #[DataProvider('newlyRequiredAnswerFields')]
    public function save_rejects_an_unanswered_required_profile_field(string $field, mixed $emptyValue): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set($field, $emptyValue)
            ->call('save')
            ->assertHasErrors([$field])
            ->assertSet('saved', false);
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
    public function contact_number_rejects_non_digit_characters(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('contactNumber', '0917-ABC-4567')
            ->call('save')
            ->assertHasErrors(['contactNumber'])
            ->assertSet('saved', false);
    }

    #[Test]
    public function contact_number_rejects_values_too_long_to_store_safely(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('contactNumber', str_repeat('9', 21))
            ->call('save')
            ->assertHasErrors(['contactNumber'])
            ->assertSet('saved', false);
    }

    #[Test]
    public function whitelisted_barangay_is_accepted(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);
    }

    /**
     * SeniorCitizen::MINIMUM_AGE gate: a birthdate making the person 20
     * used to pass step1Rules() with no age floor at all — a non-senior
     * could be registered as a real senior record.
     */
    #[Test]
    public function under_sixty_date_of_birth_is_rejected(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('dateOfBirth', now()->subYears(20)->format('Y-m-d'))
            ->call('nextStep')
            ->assertHasErrors(['dateOfBirth']);

        $this->assertDatabaseMissing('senior_citizens', ['first_name' => 'Maria', 'last_name' => 'Santos']);
    }

    #[Test]
    public function exactly_sixty_years_old_is_accepted_but_one_day_younger_is_not(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('dateOfBirth', now()->subYears(60)->format('Y-m-d'))
            ->call('nextStep')
            ->assertHasNoErrors(['dateOfBirth']);

        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('dateOfBirth', now()->subYears(60)->addDay()->format('Y-m-d'))
            ->call('nextStep')
            ->assertHasErrors(['dateOfBirth']);
    }

    #[Test]
    public function registration_date_before_date_of_birth_is_rejected(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('dateOfBirth', '1950-01-01')
            ->set('registrationDate', '1949-06-01')
            ->call('nextStep')
            ->assertHasErrors(['registrationDate']);
    }

    #[Test]
    public function registration_date_equal_to_date_of_birth_is_accepted(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('dateOfBirth', '1950-01-01')
            ->set('registrationDate', '1950-01-01')
            ->call('nextStep')
            ->assertHasNoErrors(['registrationDate']);
    }

    #[Test]
    public function consent_date_before_date_of_birth_is_rejected(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('dateOfBirth', '1950-01-01')
            ->set('consentGivenAt', '1949-06-01')
            ->call('nextStep')
            ->assertHasErrors(['consentGivenAt']);
    }

    #[Test]
    public function date_of_death_before_date_of_birth_is_rejected(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('dateOfBirth', '1950-01-01')
            ->set('status', 'deceased')
            ->set('dateOfDeath', '1949-06-01')
            ->call('nextStep')
            ->assertHasErrors(['dateOfDeath']);
    }

    #[Test]
    public function date_of_death_equal_to_date_of_birth_is_rejected(): void
    {
        // after:dateOfBirth is strict — a documented senior (60+) cannot have
        // died the same day they were born.
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('dateOfBirth', '1950-01-01')
            ->set('status', 'deceased')
            ->set('dateOfDeath', '1950-01-01')
            ->call('nextStep')
            ->assertHasErrors(['dateOfDeath']);
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

    #[Test]
    public function household_composition_fields_over_the_max_bound_are_rejected(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('step', 2)
            ->set('numChildren', 51)
            ->set('numWorkingChildren', 51)
            ->set('householdSize', 51)
            ->call('nextStep')
            ->assertHasErrors(['numChildren', 'numWorkingChildren', 'householdSize']);

        $this->assertDatabaseMissing('senior_citizens', ['first_name' => 'Maria', 'last_name' => 'Santos']);
    }

    #[Test]
    public function household_composition_fields_at_the_max_bound_are_accepted(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('numChildren', 50)
            ->set('numWorkingChildren', 50)
            ->set('householdSize', 50)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $senior = SeniorCitizen::where('first_name', 'Maria')->where('last_name', 'Santos')->firstOrFail();
        $this->assertSame(50, $senior->num_children);
        $this->assertSame(50, $senior->num_working_children);
        $this->assertSame(50, $senior->household_size);
    }

    // ── Cross-field contradiction rules (Family Composition / Dependency) ───

    #[Test]
    public function working_children_exceeding_num_children_is_rejected_on_step_2(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('step', 2)
            ->set('numChildren', 1)
            ->set('numWorkingChildren', 2)
            ->call('nextStep')
            ->assertHasErrors(['numWorkingChildren']);
    }

    #[Test]
    public function working_children_equal_to_num_children_is_accepted(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('step', 2)
            ->set('numChildren', 3)
            ->set('numWorkingChildren', 3)
            ->call('nextStep')
            ->assertHasNoErrors(['numWorkingChildren']);
    }

    #[Test]
    public function direct_save_rejects_working_children_exceeding_num_children(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('numChildren', 1)
            ->set('numWorkingChildren', 2)
            ->call('save')
            ->assertHasErrors(['numWorkingChildren']);

        $this->assertDatabaseMissing('senior_citizens', ['first_name' => 'Maria', 'last_name' => 'Santos']);
    }

    #[Test]
    public function child_financial_support_yes_with_zero_children_is_rejected(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('step', 2)
            ->set('numChildren', 0)
            ->set('childFinancialSupport', 'Yes')
            ->call('nextStep')
            ->assertHasErrors(['childFinancialSupport']);
    }

    #[Test]
    public function child_financial_support_occasional_with_zero_children_is_rejected(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('step', 2)
            ->set('numChildren', 0)
            ->set('childFinancialSupport', 'Occasional')
            ->call('nextStep')
            ->assertHasErrors(['childFinancialSupport']);
    }

    #[Test]
    public function child_financial_support_no_or_na_with_zero_children_is_accepted(): void
    {
        foreach (['No', 'N/A'] as $value) {
            $this->fillRequired(Livewire::test(ProfileSurvey::class))
                ->set('step', 2)
                ->set('numChildren', 0)
                ->set('childFinancialSupport', $value)
                ->call('nextStep')
                ->assertHasNoErrors(['childFinancialSupport']);
        }
    }

    #[Test]
    public function living_alone_with_overcrowded_household_condition_is_rejected_on_step_4(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('step', 4)
            ->set('livingWith', ['Alone'])
            ->set('householdCondition', ['Overcrowded in home'])
            ->call('nextStep')
            ->assertHasErrors(['householdCondition']);
    }

    #[Test]
    public function living_alone_with_shared_with_relatives_household_condition_is_rejected_on_step_4(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('step', 4)
            ->set('livingWith', ['Alone'])
            ->set('householdCondition', ['Shared with relatives'])
            ->call('nextStep')
            ->assertHasErrors(['householdCondition']);
    }

    #[Test]
    public function living_alone_with_non_conflicting_household_condition_is_accepted(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('step', 4)
            ->set('livingWith', ['Alone'])
            ->set('householdCondition', ['No privacy'])
            ->call('nextStep')
            ->assertHasNoErrors(['householdCondition']);
    }

    #[Test]
    public function direct_save_rejects_living_alone_with_conflicting_household_condition(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('livingWith', ['Alone'])
            ->set('householdCondition', ['Overcrowded in home'])
            ->call('save')
            ->assertHasErrors(['householdCondition']);

        $this->assertDatabaseMissing('senior_citizens', ['first_name' => 'Maria', 'last_name' => 'Santos']);
    }

    #[Test]
    public function spouse_working_other_than_na_with_single_marital_status_is_rejected_on_step_2(): void
    {
        foreach (['Yes', 'No', 'Deceased'] as $value) {
            $this->fillRequired(Livewire::test(ProfileSurvey::class))
                ->set('maritalStatus', 'Single')
                ->set('step', 2)
                ->set('spouseWorking', $value)
                ->call('nextStep')
                ->assertHasErrors(['spouseWorking']);
        }
    }

    #[Test]
    public function spouse_working_na_with_single_marital_status_is_accepted(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('maritalStatus', 'Single')
            ->set('step', 2)
            ->set('spouseWorking', 'N/A')
            ->call('nextStep')
            ->assertHasNoErrors(['spouseWorking']);
    }

    #[Test]
    public function direct_save_rejects_spouse_working_other_than_na_with_single_marital_status(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('maritalStatus', 'Single')
            ->set('spouseWorking', 'Yes')
            ->call('save')
            ->assertHasErrors(['spouseWorking']);

        $this->assertDatabaseMissing('senior_citizens', ['first_name' => 'Maria', 'last_name' => 'Santos']);
    }

    #[Test]
    public function living_with_spouse_and_single_marital_status_is_rejected_on_step_4(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('maritalStatus', 'Single')
            ->set('spouseWorking', 'N/A')
            ->set('step', 4)
            ->set('livingWith', ['Spouse'])
            ->call('nextStep')
            ->assertHasErrors(['livingWith']);
    }

    #[Test]
    public function living_with_spouse_and_deceased_spouse_working_is_rejected_on_step_4(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('spouseWorking', 'Deceased')
            ->set('step', 4)
            ->set('livingWith', ['Spouse'])
            ->call('nextStep')
            ->assertHasErrors(['livingWith']);
    }

    #[Test]
    public function living_with_spouse_and_married_status_with_spouse_working_yes_is_accepted(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('maritalStatus', 'Married')
            ->set('spouseWorking', 'Yes')
            ->set('step', 4)
            ->set('livingWith', ['Spouse'])
            ->call('nextStep')
            ->assertHasNoErrors(['livingWith']);
    }

    #[Test]
    public function direct_save_rejects_living_with_spouse_and_single_marital_status(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('maritalStatus', 'Single')
            ->set('spouseWorking', 'N/A')
            ->set('livingWith', ['Spouse'])
            ->call('save')
            ->assertHasErrors(['livingWith']);

        $this->assertDatabaseMissing('senior_citizens', ['first_name' => 'Maria', 'last_name' => 'Santos']);
    }

    #[Test]
    public function spouse_income_source_with_single_marital_status_is_rejected_on_step_5(): void
    {
        foreach (['Spouse salary', 'Spouse pension'] as $value) {
            $this->fillRequired(Livewire::test(ProfileSurvey::class))
                ->set('maritalStatus', 'Single')
                ->set('spouseWorking', 'N/A')
                ->set('step', 5)
                ->set('incomeSource', [$value])
                ->set('realAssets', ['House'])
                ->set('movableAssets', ['Vehicle'])
                ->set('problemsNeeds', ['Limited problems encountered'])
                ->call('nextStep')
                ->assertHasErrors(['incomeSource']);
        }
    }

    #[Test]
    public function spouse_income_source_with_married_status_is_accepted(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('maritalStatus', 'Married')
            ->set('spouseWorking', 'Yes')
            ->set('step', 5)
            ->set('incomeSource', ['Spouse salary', 'Spouse pension'])
            ->set('realAssets', ['House'])
            ->set('movableAssets', ['Vehicle'])
            ->set('problemsNeeds', ['Limited problems encountered'])
            ->call('nextStep')
            ->assertHasNoErrors(['incomeSource']);
    }

    #[Test]
    public function direct_save_rejects_spouse_income_source_with_single_marital_status(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('maritalStatus', 'Single')
            ->set('spouseWorking', 'N/A')
            ->set('incomeSource', ['Spouse pension'])
            ->call('save')
            ->assertHasErrors(['incomeSource']);

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
            // Non-zero so the default child_financial_support of 'Yes' below
            // doesn't itself violate the "no support without children" rule.
            'num_children' => 2,
            'num_working_children' => 0,
            'gender' => 'Female',
            'marital_status' => 'Widowed',
            'child_financial_support' => 'Yes',
            'spouse_working' => 'N/A',
            'educational_attainment' => 'High School Graduate',
            'living_with' => ['Children'],
            'real_assets' => ['No known assets'],
            'movable_assets' => ['No known assets'],
            'monthly_income_range' => '5,000 - 10,000',
            'problems_needs' => ['Limited problems encountered'],
            'medical_concern' => ['Physically Healthy'],
            'social_emotional_concern' => ['Living in a healthy environment'],
            'dental_concern' => ['Healthy Teeth'],
            'optical_concern' => ['Healthy Eyes'],
            'hearing_concern' => ['Healthy Hearing'],
            'healthcare_difficulty' => ['Healthcare is accessible'],
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

    // ── Name validation (App\Support\NameRules) ─────────────────────────────
    // Reported by the IT Expert: numeric/alphanumeric names were accepted on
    // New Profile, and invalid characters survived an Edit. These assert the
    // server-side rule directly via Livewire::test() — i.e. exactly a
    // "manually constructed request", bypassing the nameGuard() JS entirely —
    // so a client that skips the JS validation is still rejected.

    public static function invalidNames(): array
    {
        return [
            'trailing digits' => ['Juan123'],
            'leading digits' => ['123Juan'],
            'digit in middle' => ['J0hn'],
            'letters then digits' => ['Maria2026'],
            'digits only' => ['12345'],
            'trailing single digit' => ['Jezreel1'],
            'leetspeak digit' => ['R4mos'],
            'at symbol' => ['Juan@'],
            'hash symbol' => ['Maria#'],
            'dollar symbol' => ['Pedro$'],
            'underscore' => ['Test_User'],
            'percent symbol' => ['Ana%'],
            'asterisk' => ['John*'],
            'angle brackets' => ['Juan<>'],
            'script tag' => ['<script>'],
        ];
    }

    public static function validNames(): array
    {
        return [
            'simple' => ['Juan'],
            'multi word' => ['Juan Dela Cruz'],
            'hyphenated' => ['Anne-Marie'],
            'apostrophe' => ["O'Connor"],
            'abbreviation with period' => ['Ma. Teresa'],
            'accented letter' => ['José'],
            'combining tilde' => ['Dela Peña'],
        ];
    }

    #[Test]
    public function create_profile_rejects_invalid_first_and_last_names(): void
    {
        foreach (self::invalidNames() as $label => [$name]) {
            $this->fillRequired(Livewire::test(ProfileSurvey::class))
                ->set('firstName', $name)
                ->call('nextStep')
                ->assertHasErrors(['firstName'], "firstName should reject [{$label}]: {$name}");

            $this->fillRequired(Livewire::test(ProfileSurvey::class))
                ->set('lastName', $name)
                ->call('nextStep')
                ->assertHasErrors(['lastName'], "lastName should reject [{$label}]: {$name}");
        }

        $this->assertDatabaseMissing('senior_citizens', ['last_name' => 'Santos', 'first_name' => 'Juan123']);
    }

    #[Test]
    public function create_profile_accepts_legitimate_names(): void
    {
        foreach (self::validNames() as [$name]) {
            $this->fillRequired(Livewire::test(ProfileSurvey::class))
                ->set('firstName', $name)
                ->call('save')
                ->assertHasNoErrors(['firstName'])
                ->assertSet('saved', true);

            $this->assertDatabaseHas('senior_citizens', ['first_name' => $name, 'last_name' => 'Santos']);

            SeniorCitizen::where('first_name', $name)->where('last_name', 'Santos')->delete();
        }
    }

    #[Test]
    public function middle_name_and_name_extension_follow_the_same_character_rule(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('middleName', 'Santos123')
            ->call('nextStep')
            ->assertHasErrors(['middleName']);

        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('nameExtension', 'Jr#')
            ->call('nextStep')
            ->assertHasErrors(['nameExtension']);
    }

    #[Test]
    public function name_extension_accepts_common_suffixes(): void
    {
        foreach (['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'] as $suffix) {
            $this->fillRequired(Livewire::test(ProfileSurvey::class))
                ->set('nameExtension', $suffix)
                ->call('nextStep')
                ->assertHasNoErrors(['nameExtension']);
        }
    }

    #[Test]
    public function middle_name_and_name_extension_remain_optional(): void
    {
        // nullable — an empty middleName/nameExtension must not trip the
        // character-format rule (that's a different concern from
        // required-ness, which neither field has).
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('middleName', '')
            ->set('nameExtension', '')
            ->call('save')
            ->assertHasNoErrors(['middleName', 'nameExtension'])
            ->assertSet('saved', true);
    }

    #[Test]
    public function edit_profile_rejects_invalid_name_characters_same_as_create(): void
    {
        // The IT Expert's second report: "pag nag-edit ako ng name may chars
        // na naretain" — Edit must reject exactly like Create. Both forms
        // are the same ProfileSurvey component/step1Rules(), so this is the
        // regression guard for that shared path staying shared.
        $senior = $this->makeSenior();

        Livewire::test(ProfileSurvey::class, ['seniorId' => $senior->id])
            ->set('firstName', 'Maria123')
            ->call('nextStep')
            ->assertHasErrors(['firstName']);

        Livewire::test(ProfileSurvey::class, ['seniorId' => $senior->id])
            ->set('lastName', 'Maria123')
            ->call('save')
            ->assertHasErrors(['lastName']);

        $senior->refresh();
        $this->assertSame('Juan', $senior->first_name);
        $this->assertSame('Dela Cruz', $senior->last_name);
    }

    #[Test]
    public function edit_profile_accepts_a_legitimate_name_change(): void
    {
        $senior = $this->makeSenior();

        Livewire::test(ProfileSurvey::class, ['seniorId' => $senior->id])
            ->set('firstName', 'Maria')
            ->set('lastName', 'Maria Clara')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $senior->refresh();
        $this->assertSame('Maria', $senior->first_name);
        $this->assertSame('Maria Clara', $senior->last_name);
    }

    #[Test]
    public function edit_profile_does_not_silently_retain_stale_invalid_name_after_a_rejected_save(): void
    {
        // Guards the exact "characters retained" failure mode: a rejected
        // save() must leave the STORED record untouched, not partially
        // apply the attempted (invalid) value.
        $senior = $this->makeSenior();

        Livewire::test(ProfileSurvey::class, ['seniorId' => $senior->id])
            ->set('firstName', 'Juan99')
            ->call('save')
            ->assertHasErrors(['firstName']);

        $this->assertDatabaseHas('senior_citizens', ['id' => $senior->id, 'first_name' => 'Juan']);
        $this->assertDatabaseMissing('senior_citizens', ['id' => $senior->id, 'first_name' => 'Juan99']);
    }
}
