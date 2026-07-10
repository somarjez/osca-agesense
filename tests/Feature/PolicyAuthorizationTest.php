<?php

namespace Tests\Feature;

use App\Livewire\Surveys\ProfileSurvey;
use App\Livewire\Surveys\QolSurveyForm;
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
 * Task 2 — verifies SeniorCitizenPolicy is actually wired into every
 * Senior-touching controller and Livewire component, not just defined.
 *
 * Controller assertions double as regression coverage that authorize() does
 * not change any status code already enforced by route-group role
 * middleware (abilities were designed in Task 1 to mirror those gates
 * exactly). The Livewire assertions are the load-bearing new coverage:
 * ProfileSurvey/QolSurveyForm actions bypass HTTP route middleware
 * entirely, so authorize() inside mount()/save() is the only gate they get.
 */
class PolicyAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $encoder;

    private User $viewer;

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
            ['email' => 'pauth-admin@osca.local'],
            ['name' => 'PAuth Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);

        $this->encoder = User::firstOrCreate(
            ['email' => 'pauth-encoder@osca.local'],
            ['name' => 'PAuth Encoder', 'password' => Hash::make('password')]
        );
        $this->encoder->syncRoles(['encoder']);

        $this->viewer = User::firstOrCreate(
            ['email' => 'pauth-viewer@osca.local'],
            ['name' => 'PAuth Viewer', 'password' => Hash::make('password')]
        );
        $this->viewer->syncRoles(['viewer']);

        $this->senior = SeniorCitizen::create([
            'osca_id' => 'PAU-'.uniqid(),
            'first_name' => 'Pauth',
            'last_name' => 'TestSenior',
            'barangay' => 'Pagsanjan',
            'date_of_birth' => '1950-06-15',
            'gender' => 'Male',
            'status' => 'active',
            'encoded_by' => 'Test',
        ]);
    }

    // ── SeniorCitizenController ─────────────────────────────────────────────

    #[Test]
    public function senior_index_show_export_allowed_for_all_three_roles(): void
    {
        foreach ([$this->admin, $this->encoder, $this->viewer] as $user) {
            $this->actingAs($user)->get(route('seniors.index'))->assertOk();
            $this->actingAs($user)->get(route('seniors.show', $this->senior))->assertOk();
            $this->actingAs($user)->get(route('seniors.export', $this->senior))->assertOk();
        }
    }

    #[Test]
    public function senior_edit_allowed_for_admin_and_encoder_forbidden_for_viewer(): void
    {
        $this->actingAs($this->admin)->get(route('seniors.edit', $this->senior))->assertOk();
        $this->actingAs($this->encoder)->get(route('seniors.edit', $this->senior))->assertOk();
        $this->actingAs($this->viewer)->get(route('seniors.edit', $this->senior))->assertForbidden();
    }

    #[Test]
    public function senior_destroy_allowed_for_admin_forbidden_for_encoder_and_viewer(): void
    {
        $victim = SeniorCitizen::create([
            'osca_id' => 'PAU-'.uniqid(),
            'first_name' => 'Delete',
            'last_name' => 'Me',
            'barangay' => 'Pagsanjan',
            'date_of_birth' => '1950-06-15',
            'gender' => 'Female',
            'status' => 'active',
            'encoded_by' => 'Test',
        ]);

        $this->actingAs($this->encoder)->delete(route('seniors.destroy', $victim))->assertForbidden();
        $this->actingAs($this->viewer)->delete(route('seniors.destroy', $victim))->assertForbidden();
        $this->actingAs($this->admin)->delete(route('seniors.destroy', $victim))
            ->assertRedirect(route('seniors.index'));
    }

    // ── RecommendationController ────────────────────────────────────────────

    #[Test]
    public function recommendation_index_and_show_allowed_for_all_three_roles(): void
    {
        foreach ([$this->admin, $this->encoder, $this->viewer] as $user) {
            $this->actingAs($user)->get(route('recommendations.index'))->assertOk();
            $this->actingAs($user)->get(route('recommendations.show', $this->senior))->assertOk();
        }
    }

    // ── SurveyController ─────────────────────────────────────────────────────

    #[Test]
    public function profile_create_allowed_for_admin_and_encoder_forbidden_for_viewer(): void
    {
        $this->actingAs($this->admin)->get(route('surveys.profile.create'))->assertOk();
        $this->actingAs($this->encoder)->get(route('surveys.profile.create'))->assertOk();
        $this->actingAs($this->viewer)->get(route('surveys.profile.create'))->assertForbidden();
    }

    #[Test]
    public function profile_create_for_existing_senior_allowed_for_admin_and_encoder_forbidden_for_viewer(): void
    {
        $this->actingAs($this->admin)->get(route('surveys.profile.create', $this->senior))->assertOk();
        $this->actingAs($this->encoder)->get(route('surveys.profile.create', $this->senior))->assertOk();
        $this->actingAs($this->viewer)->get(route('surveys.profile.create', $this->senior))->assertForbidden();
    }

    #[Test]
    public function qol_create_allowed_for_admin_and_encoder_forbidden_for_viewer(): void
    {
        $this->actingAs($this->admin)->get(route('surveys.qol.create', $this->senior))->assertOk();
        $this->actingAs($this->encoder)->get(route('surveys.qol.create', $this->senior))->assertOk();
        $this->actingAs($this->viewer)->get(route('surveys.qol.create', $this->senior))->assertForbidden();
    }

    // ── ReportController ─────────────────────────────────────────────────────

    #[Test]
    public function report_pages_allowed_for_all_three_roles(): void
    {
        foreach ([$this->admin, $this->encoder, $this->viewer] as $user) {
            $this->actingAs($user)->get(route('reports.gis'))->assertOk();
            $this->actingAs($user)->get(route('reports.cluster'))->assertOk();
            $this->actingAs($user)->get(route('reports.risk'))->assertOk();
            $this->actingAs($user)->get(route('reports.barangay', 'Anibong'))->assertOk();
        }
    }

    // ── Livewire ProfileSurvey — the actual gap this task closes ───────────

    #[Test]
    public function profile_survey_mount_for_create_allowed_for_admin_and_encoder_forbidden_for_viewer(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(ProfileSurvey::class)->assertOk();

        $this->actingAs($this->encoder);
        Livewire::test(ProfileSurvey::class)->assertOk();

        $this->actingAs($this->viewer);
        Livewire::test(ProfileSurvey::class)->assertForbidden();
    }

    #[Test]
    public function profile_survey_mount_for_edit_allowed_for_admin_and_encoder_forbidden_for_viewer(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(ProfileSurvey::class, ['seniorId' => $this->senior->id])->assertOk();

        $this->actingAs($this->encoder);
        Livewire::test(ProfileSurvey::class, ['seniorId' => $this->senior->id])->assertOk();

        $this->actingAs($this->viewer);
        Livewire::test(ProfileSurvey::class, ['seniorId' => $this->senior->id])->assertForbidden();
    }

    #[Test]
    public function profile_survey_save_still_persists_for_admin_after_policy_rewire(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ProfileSurvey::class)
            ->set('firstName', 'Wired')
            ->set('lastName', 'Policy')
            ->set('barangay', 'Pinagsanjan')
            ->set('dateOfBirth', '1948-05-02')
            ->call('save')
            ->assertSet('saved', true);

        $this->assertDatabaseHas('senior_citizens', [
            'first_name' => 'Wired',
            'last_name' => 'Policy',
        ]);
    }

    // ── Livewire QolSurveyForm ───────────────────────────────────────────────

    #[Test]
    public function qol_survey_form_mount_allowed_for_admin_and_encoder_forbidden_for_viewer(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(QolSurveyForm::class, ['seniorId' => $this->senior->id])->assertOk();

        $this->actingAs($this->encoder);
        Livewire::test(QolSurveyForm::class, ['seniorId' => $this->senior->id])->assertOk();

        $this->actingAs($this->viewer);
        Livewire::test(QolSurveyForm::class, ['seniorId' => $this->senior->id])->assertForbidden();
    }
}
