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
 * Regression coverage for the progress rail and save readiness. Wizard
 * progress tracks the current step, while stepStatusText()/canSave() track
 * required answers independently across the full profile.
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
            ->set('childFinancialSupport', 'Yes')
            ->set('spouseWorking', 'N/A')
            ->set('educationalAttainment', 'High School Graduate')
            ->set('livingWith', ['Children'])
            ->set('monthlyIncomeRange', '5,000 - 10,000');
    }

    #[Test]
    public function progress_is_not_100_percent_on_step_2_even_when_required_fields_are_filled(): void
    {
        $component = $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('step', 2);

        $percent = $component->instance()->completionPercent();

        $this->assertLessThan(100, $percent, 'Progress must not read 100% while still on Step 2 of 6.');
        $this->assertSame(20, $percent); // (2-1)/(6-1)*100 = 20
    }

    #[Test]
    public function progress_reaches_100_percent_only_on_the_final_step(): void
    {
        $component = Livewire::test(ProfileSurvey::class)->set('step', 6);

        $this->assertSame(100, $component->instance()->completionPercent());
    }

    #[Test]
    public function progress_is_0_percent_on_step_1(): void
    {
        $component = Livewire::test(ProfileSurvey::class)->set('step', 1);

        $this->assertSame(0, $component->instance()->completionPercent());
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
