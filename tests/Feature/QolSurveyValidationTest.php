<?php

namespace Tests\Feature;

use App\Jobs\RunMlPipeline;
use App\Livewire\Surveys\QolSurveyForm;
use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 3 — input validation hardening for QolSurveyForm section H (h1/h2),
 * which previously fell into validateSection()'s default case and received
 * zero validation. h1/h2 stay optional (the UI allows skipping section H),
 * so they must be nullable while still bounded to the 1-5 Likert range.
 */
class QolSurveyValidationTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private SeniorCitizen $senior;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::firstOrCreate(
            ['email' => 'qolvalid-admin@osca.local'],
            ['name' => 'QolValid Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);

        $this->senior = SeniorCitizen::create([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
        ]);

        $this->actingAs($this->admin);
    }

    /** Fill sections A-G with valid answers so validateSection() on step 8 is the only thing under test. */
    private function fillSectionsAtoG($component)
    {
        return $component
            ->set('a1', 4)->set('a2', 4)->set('a3', 4)->set('a4', 4)
            ->set('b1', 4)->set('b2', 4)->set('b3', 4)->set('b4', 4)->set('b5', 4)
            ->set('c1', 4)->set('c2', 4)->set('c3', 4)->set('c4', 4)
            ->set('d1', 4)->set('d2', 4)->set('d3', 4)->set('d4', 4)
            ->set('e1', 4)->set('e2', 4)->set('e3', 4)->set('e4', 4)->set('e5', 4)
            ->set('f1', 4)->set('f2', 4)->set('f3', 4)->set('f4', 4)
            ->set('g1', 4)->set('g2', 4)->set('g3', 4)
            ->set('step', 8);
    }

    #[Test]
    public function h1_outside_1_to_5_is_rejected(): void
    {
        $this->fillSectionsAtoG(Livewire::test(QolSurveyForm::class, ['seniorId' => $this->senior->id]))
            ->set('h1', 6)
            ->call('nextStep')
            ->assertHasErrors(['h1']);
    }

    #[Test]
    public function h2_below_1_is_rejected(): void
    {
        $this->fillSectionsAtoG(Livewire::test(QolSurveyForm::class, ['seniorId' => $this->senior->id]))
            ->set('h2', 0)
            ->call('nextStep')
            ->assertHasErrors(['h2']);
    }

    #[Test]
    public function omitting_h1_and_h2_entirely_still_passes_section_validation(): void
    {
        $this->fillSectionsAtoG(Livewire::test(QolSurveyForm::class, ['seniorId' => $this->senior->id]))
            ->call('nextStep')
            ->assertHasNoErrors();
    }

    #[Test]
    public function omitting_h1_and_h2_still_allows_full_submission(): void
    {
        Queue::fake();

        $this->fillSectionsAtoG(Livewire::test(QolSurveyForm::class, ['seniorId' => $this->senior->id]))
            ->call('submitSurvey')
            ->assertHasNoErrors();

        Queue::assertPushed(RunMlPipeline::class);
        $this->assertDatabaseHas('qol_surveys', [
            'senior_citizen_id' => $this->senior->id,
            'h1_belief_comfort' => null,
            'h2_belief_practice' => null,
            'status' => 'processed',
        ]);

        // Reload-survival marker must be set alongside the dispatch — see
        // MlController::runSingle(), the working "Re-run Assessment" path
        // this mirrors. Without it the profile page's poll banner can't
        // survive a reload while waiting on Render's queue drain.
        $this->assertNotNull($this->senior->fresh()->ml_queued_at);
    }

    #[Test]
    public function valid_h1_h2_within_range_are_accepted(): void
    {
        $this->fillSectionsAtoG(Livewire::test(QolSurveyForm::class, ['seniorId' => $this->senior->id]))
            ->set('h1', 3)
            ->set('h2', 5)
            ->call('nextStep')
            ->assertHasNoErrors();
    }

    /**
     * Regression coverage for the audit's Critical finding (TC-REC-07): a
     * completely blank survey — reached by jumping the stepper straight to
     * step 8 via goToStep() (no wire:model ever touched, so every a1..g3
     * stays null) then calling submitSurvey() directly — used to persist
     * with status='processed' and get scored by the live ML model with false
     * confidence. validateAllSections() must now block this before anything
     * is written or dispatched.
     */
    #[Test]
    public function completely_blank_survey_is_rejected_not_scored(): void
    {
        Queue::fake();

        Livewire::test(QolSurveyForm::class, ['seniorId' => $this->senior->id])
            ->call('goToStep', 8)
            ->call('submitSurvey')
            ->assertHasErrors(['a1']);

        Queue::assertNotPushed(RunMlPipeline::class);
        $this->assertDatabaseMissing('qol_surveys', [
            'senior_citizen_id' => $this->senior->id,
        ]);
    }

    /** A single missing required field (not a fully blank survey) must also block submission. */
    #[Test]
    public function partially_incomplete_survey_is_rejected_and_bounces_to_the_incomplete_step(): void
    {
        Queue::fake();

        $component = $this->fillSectionsAtoG(Livewire::test(QolSurveyForm::class, ['seniorId' => $this->senior->id]))
            ->set('d3', null) // Section D (step 4) now incomplete
            ->call('goToStep', 8)
            ->call('submitSurvey');

        $component->assertHasErrors(['d3']);
        $component->assertSet('step', 4); // bounced back to the first incomplete step, not left on 8

        Queue::assertNotPushed(RunMlPipeline::class);
        $this->assertDatabaseMissing('qol_surveys', [
            'senior_citizen_id' => $this->senior->id,
        ]);
    }

    /**
     * Regression for Issue 5: submitting with a section left blank used to
     * spin on "Processing…" forever with no visible error — confirmSubmit()
     * set showConfirm=true unconditionally, and validation only happened
     * inside submitSurvey() (reached when the modal's own confirm button is
     * clicked), whose failure path left showConfirm stuck true (modal open,
     * error banner rendered behind it) and the Alpine `submitting` flag
     * never reset. confirmSubmit() now validates BEFORE opening the modal,
     * so an incomplete survey never reaches confirmation at all.
     */
    #[Test]
    public function confirm_submit_on_an_incomplete_survey_never_opens_the_dialog(): void
    {
        Queue::fake();

        $component = $this->fillSectionsAtoG(Livewire::test(QolSurveyForm::class, ['seniorId' => $this->senior->id]))
            ->set('d3', null) // Section D (step 4) now incomplete
            ->call('goToStep', 8)
            ->call('confirmSubmit');

        $component->assertHasErrors(['d3']);
        $component->assertSet('showConfirm', false);
        $component->assertSet('step', 4); // bounced back to the first incomplete step

        Queue::assertNotPushed(RunMlPipeline::class);
    }

    /**
     * Defense-in-depth path: even if showConfirm somehow ends up true before
     * submitSurvey() re-validates (e.g. a stale/tampered direct call), the
     * modal must not stay stuck open on failure.
     */
    #[Test]
    public function submit_survey_closes_the_dialog_if_validation_still_fails(): void
    {
        Queue::fake();

        $component = $this->fillSectionsAtoG(Livewire::test(QolSurveyForm::class, ['seniorId' => $this->senior->id]))
            ->set('d3', null)
            ->set('showConfirm', true)
            ->call('goToStep', 8)
            ->call('submitSurvey');

        $component->assertHasErrors(['d3']);
        $component->assertSet('showConfirm', false);
    }
}
