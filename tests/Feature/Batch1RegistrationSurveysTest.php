<?php

namespace Tests\Feature;

use App\Livewire\Surveys\ProfileSurvey;
use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Batch1RegistrationSurveysTest extends TestCase
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
    public function saving_a_profile_shows_the_success_modal_with_osca_id_and_survey_link(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ProfileSurvey::class)
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
            ->set('monthlyIncomeRange', '5,000 - 10,000')
            ->call('save')
            ->assertSet('saved', true)
            ->assertSee('Profile Saved')
            ->assertSee('ANI-'.now()->format('Y').'-')
            ->assertSee('Take QoL Survey');
    }

    #[Test]
    public function opening_new_survey_for_a_senior_with_a_draft_resumes_that_draft(): void
    {
        $senior = $this->makeSenior();
        $draft = QolSurvey::create([
            'senior_citizen_id' => $senior->id,
            'survey_date' => now()->format('Y-m-d'),
            'status' => 'draft',
            'a1_enjoy_life' => 3,
        ]);

        $this->actingAs($this->admin)
            ->get(route('surveys.qol.create', $senior))
            ->assertOk()
            ->assertViewHas('surveyId', $draft->id);
    }

    #[Test]
    public function opening_new_survey_for_a_senior_without_a_draft_starts_blank(): void
    {
        $senior = $this->makeSenior();

        $this->actingAs($this->admin)
            ->get(route('surveys.qol.create', $senior))
            ->assertOk()
            ->assertViewHas('surveyId', null);
    }

    #[Test]
    public function senior_record_shows_continue_draft_when_a_draft_exists(): void
    {
        $senior = $this->makeSenior();
        $draft = QolSurvey::create([
            'senior_citizen_id' => $senior->id,
            'survey_date' => now()->format('Y-m-d'),
            'status' => 'draft',
        ]);

        $this->actingAs($this->admin)
            ->get(route('seniors.show', $senior))
            ->assertOk()
            ->assertSee('Continue draft')
            ->assertSee(route('surveys.qol.edit', $draft), false);
    }

    #[Test]
    public function qol_index_search_matches_senior_name_and_osca_id(): void
    {
        $alice = $this->makeSenior(['first_name' => 'Alice', 'last_name' => 'Reyes']);
        $bob = $this->makeSenior(['first_name' => 'Bob', 'last_name' => 'Tan']);
        foreach ([$alice, $bob] as $s) {
            QolSurvey::create([
                'senior_citizen_id' => $s->id,
                'survey_date' => now()->format('Y-m-d'),
                'status' => 'submitted',
            ]);
        }

        // Search by name
        $this->actingAs($this->admin)
            ->get(route('surveys.qol.index', ['search' => 'Alice']))
            ->assertOk()
            ->assertSee('Alice Reyes')
            ->assertDontSee('Bob Tan');

        // Search by OSCA ID
        $this->actingAs($this->admin)
            ->get(route('surveys.qol.index', ['search' => $bob->osca_id]))
            ->assertOk()
            ->assertSee('Bob Tan')
            ->assertDontSee('Alice Reyes');
    }
}
