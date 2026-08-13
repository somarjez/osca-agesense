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
 * Regression coverage for the reported bug: "Required Fields" showed 100%
 * immediately after only Step 1 (Identifying Information) was filled, while
 * the rail simultaneously said "Step 2 of 6" and "Fill out all required
 * fields in Step 2 to proceed" — self-contradictory, since Step 2 has no
 * required fields at all. completionPercent() now tracks wizard position
 * (rendered as "Progress"); stepStatusText() tracks submit-readiness
 * (Step 1's required fields) independently. See ProfileSurvey.php's
 * docblocks on both methods.
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
            ->set('dateOfBirth', '1948-05-02');
    }

    #[Test]
    public function progress_is_not_100_percent_after_only_step_1_is_filled(): void
    {
        // This is the exact reported scenario: Step 1 done, sitting on
        // Step 2 of 6. The old ratio-based percent hit 100% here; the new
        // wizard-position percent must not.
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
    public function submit_readiness_text_says_in_progress_when_required_fields_are_incomplete(): void
    {
        $component = Livewire::test(ProfileSurvey::class)
            ->set('firstName', 'Maria')
            ->set('step', 3); // moved on, but lastName/barangay/dob still unset

        $this->assertSame('In progress.', $component->instance()->stepStatusText());
    }

    #[Test]
    public function submit_readiness_text_says_lets_get_started_when_no_required_field_is_filled(): void
    {
        // $status defaults to 'active' on a fresh mount (a real, pre-filled
        // required field, not an empty one) — clear it explicitly to reach
        // the genuine zero-filled state.
        $component = Livewire::test(ProfileSurvey::class)->set('status', '');

        $this->assertSame("Let's get started.", $component->instance()->stepStatusText());
    }
}
