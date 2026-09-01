<?php

namespace Tests\Feature;

use App\Livewire\Surveys\ProfileSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for the progress rail and save readiness.
 * completionPercent() tracks required-field completion across the entire
 * profile (not wizard step position — see completionPercent()'s docblock),
 * and stepStatusText()/canSave() track required answers plus cross-field
 * validity, independently of which step is active.
 */
class ProfileSurveyProgressTest extends TestCase
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
            ['email' => 'psprogress-admin@osca.local'],
            ['name' => 'PSProgress Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);

        $this->actingAs($this->admin);
    }

    private function fillRequired($component)
    {
        return $component
            ->set('firstName', 'Maria')
            ->set('lastName', 'Santos')
            ->set('barangay', 'Anibong')
            ->set('dateOfBirth', '1948-05-02')
            ->set('gender', 'Female')
            ->set('maritalStatus', 'Widowed')
            ->set('numChildren', 2)
            ->set('childFinancialSupport', 'Yes')
            // Widowed requires spouseWorking = 'Deceased' (see spouseWorkingAllowedValues()).
            ->set('spouseWorking', 'Deceased')
            ->set('educationalAttainment', 'High School Graduate')
            ->set('livingWith', ['Children'])
            // Non-1 so it's consistent with 'Children' above under the
            // reverse householdSize===1-forces-Alone rule (step4Rules()).
            ->set('householdSize', 3)
            ->set('monthlyIncomeRange', '5,000 - 10,000');
    }

    /** Mirrors completionPercent()'s own formula via the private requiredFieldStatus(), so assertions stay correct if the required-field set ever changes. */
    private function expectedPercent($component): int
    {
        $ref = new \ReflectionMethod($component->instance(), 'requiredFieldStatus');
        [$filled, $total] = $ref->invoke($component->instance());

        return $total === 0 ? 0 : (int) round(($filled / $total) * 100);
    }

    #[Test]
    public function progress_is_not_100_percent_when_jumping_to_the_final_step_with_fields_still_missing(): void
    {
        // This is the literal repro of the reported bug: goToStep() lets a
        // user land on Step 6 with nothing filled, and completionPercent()
        // must not read 100% just because step === totalSteps.
        $component = Livewire::test(ProfileSurvey::class)->set('step', 6);

        $percent = $component->instance()->completionPercent();

        $this->assertLessThan(100, $percent, 'Progress must not read 100% on the final step while required fields remain unfilled.');
        $this->assertSame($this->expectedPercent($component), $percent);
    }

    #[Test]
    public function progress_reaches_100_percent_once_every_required_field_is_filled_regardless_of_step(): void
    {
        $onStep2 = $this->fillRequired(Livewire::test(ProfileSurvey::class))->set('step', 2);
        $onStep6 = $this->fillRequired(Livewire::test(ProfileSurvey::class))->set('step', 6);

        $this->assertSame(100, $onStep2->instance()->completionPercent());
        $this->assertSame(100, $onStep6->instance()->completionPercent());
    }

    #[Test]
    public function progress_on_a_fresh_profile_reflects_only_the_pre_filled_defaults(): void
    {
        // applyCreateDefaults() pre-fills several exclusive-token required
        // fields on mount(), so a brand-new profile does not start at 0%.
        $component = Livewire::test(ProfileSurvey::class)->set('step', 1);

        $percent = $component->instance()->completionPercent();

        $this->assertSame($this->expectedPercent($component), $percent);
        $this->assertGreaterThan(0, $percent);
        $this->assertLessThan(100, $percent);
    }

    #[Test]
    public function submit_readiness_text_reflects_required_fields_not_step_position(): void
    {
        // Required fields done, but only on Step 1 (haven't stepped
        // anywhere else yet) — must already say "Ready to submit.", since
        // stepStatusText() is decoupled from wizard position.
        $ready = $this->fillRequired(Livewire::test(ProfileSurvey::class));

        $this->assertSame('Ready to submit.', $ready->instance()->stepStatusText());
    }

    #[Test]
    public function a_missing_required_field_on_a_later_step_blocks_save_readiness(): void
    {
        $component = $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('livingWith', []);

        $this->assertFalse($component->instance()->canSave());
        $this->assertStringContainsString('Living arrangement', $component->instance()->stepStatusText());
    }

    #[Test]
    public function disabled_save_profile_explains_that_all_required_fields_must_be_answered(): void
    {
        Livewire::test(ProfileSurvey::class)
            ->assertSee('Answer all required fields.');
    }

    #[Test]
    public function submit_readiness_text_says_in_progress_when_required_fields_are_incomplete(): void
    {
        $component = Livewire::test(ProfileSurvey::class)
            ->set('firstName', 'Maria')
            ->set('step', 3); // moved on, but lastName/barangay/dob still unset

        $this->assertStringStartsWith(
            'In progress. Missing: Last name, Barangay, Date of birth',
            $component->instance()->stepStatusText()
        );
    }

    #[Test]
    public function submit_readiness_text_is_in_progress_when_only_neutral_defaults_are_filled(): void
    {
        $component = Livewire::test(ProfileSurvey::class);

        $this->assertStringStartsWith('In progress. Missing: First name', $component->instance()->stepStatusText());
    }
}
