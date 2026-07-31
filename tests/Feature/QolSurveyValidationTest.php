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
}
