<?php

namespace Tests\Feature;

use App\Livewire\Surveys\ProfileSurvey;
use App\Models\ProfileDraft;
use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProfileSurveyDefaultsTest extends TestCase
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

        // A leftover create-mode draft would win over defaults in mount().
        ProfileDraft::whereNull('senior_citizen_id')
            ->where('created_by', $this->admin->id)
            ->delete();

        $this->actingAs($this->admin);
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

    /** Fill the step-1 required fields so save() passes validation. */
    private function fillRequired($component)
    {
        return $component
            ->set('firstName', 'Maria')
            ->set('lastName', 'Santos')
            ->set('barangay', 'Anibong')
            ->set('dateOfBirth', '1948-05-02');
    }

    #[Test]
    public function create_mount_preselects_the_neutral_defaults(): void
    {
        Livewire::test(ProfileSurvey::class)
            ->assertSet('medicalConcern', ['Physically Healthy'])
            ->assertSet('socialEmotionalConcern', ['Living in a healthy environment'])
            ->assertSet('dentalConcern', ['Healthy Teeth'])
            ->assertSet('opticalConcern', ['Healthy Eyes'])
            ->assertSet('hearingConcern', ['Healthy Hearing'])
            ->assertSet('healthcareDifficulty', ['Healthcare is accessible'])
            ->assertSet('problemsNeeds', ['Limited problems encountered'])
            ->assertSet('realAssets', ['No known assets'])
            ->assertSet('movableAssets', ['No known assets'])
            // Deliberately NOT defaulted (ML-sensitive or user-decided blank-able).
            ->assertSet('livingWith', [])
            ->assertSet('educationalAttainment', '')
            ->assertSet('monthlyIncomeRange', '')
            ->assertSet('specialization', [])
            ->assertSet('communityService', [])
            ->assertSet('householdCondition', [])
            ->assertSet('incomeSource', []);
    }

    #[Test]
    public function edit_mount_applies_no_defaults_when_columns_are_null(): void
    {
        $senior = $this->makeSenior();

        Livewire::test(ProfileSurvey::class, ['seniorId' => $senior->id])
            ->assertSet('medicalConcern', [])
            ->assertSet('socialEmotionalConcern', [])
            ->assertSet('dentalConcern', [])
            ->assertSet('opticalConcern', [])
            ->assertSet('hearingConcern', [])
            ->assertSet('healthcareDifficulty', [])
            ->assertSet('problemsNeeds', [])
            ->assertSet('realAssets', [])
            ->assertSet('movableAssets', []);
    }

    #[Test]
    public function edit_mount_shows_stored_data_untouched(): void
    {
        $senior = $this->makeSenior([
            'medical_concern' => ['Diabetes'],
            'real_assets' => ['House'],
        ]);

        Livewire::test(ProfileSurvey::class, ['seniorId' => $senior->id])
            ->assertSet('medicalConcern', ['Diabetes'])
            ->assertSet('realAssets', ['House']);
    }

    #[Test]
    public function existing_draft_wins_over_create_defaults(): void
    {
        ProfileDraft::create([
            'senior_citizen_id' => null,
            'created_by' => $this->admin->id,
            'step' => 5,
            'data' => ['medicalConcern' => ['Diabetes']],
        ]);

        Livewire::test(ProfileSurvey::class)
            ->assertSet('step', 5)
            ->assertSet('medicalConcern', ['Diabetes'])
            // Keys absent from a pre-feature draft stay empty — no defaults injected.
            ->assertSet('dentalConcern', [])
            ->assertSet('realAssets', []);
    }

    #[Test]
    public function saving_an_untouched_create_persists_the_defaults(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->call('save')
            ->assertSet('saved', true);

        $senior = SeniorCitizen::where('first_name', 'Maria')->where('last_name', 'Santos')->firstOrFail();

        $this->assertSame(['Physically Healthy'], $senior->medical_concern);
        $this->assertSame(['Living in a healthy environment'], $senior->social_emotional_concern);
        $this->assertSame(['Healthy Teeth'], $senior->dental_concern);
        $this->assertSame(['Healthy Eyes'], $senior->optical_concern);
        $this->assertSame(['Healthy Hearing'], $senior->hearing_concern);
        $this->assertSame(['Healthcare is accessible'], $senior->healthcare_difficulty);
        $this->assertSame(['Limited problems encountered'], $senior->problems_needs);
        $this->assertSame(['No known assets'], $senior->real_assets);
        $this->assertSame(['No known assets'], $senior->movable_assets);
        $this->assertNull($senior->living_with);
    }

    #[Test]
    public function save_drops_the_exclusive_token_when_mixed_with_real_answers(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('medicalConcern', ['Physically Healthy', 'Diabetes'])
            ->set('livingWith', ['Alone', 'Spouse'])
            ->set('realAssets', ['No known assets', 'House'])
            ->call('save')
            ->assertSet('saved', true);

        $senior = SeniorCitizen::where('first_name', 'Maria')->where('last_name', 'Santos')->firstOrFail();

        $this->assertSame(['Diabetes'], $senior->medical_concern);
        $this->assertSame(['Spouse'], $senior->living_with);
        $this->assertSame(['House'], $senior->real_assets);
    }

    #[Test]
    public function exclusive_token_alone_survives_save_unchanged(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('dentalConcern', ['Healthy Teeth'])
            ->call('save')
            ->assertSet('saved', true);

        $senior = SeniorCitizen::where('first_name', 'Maria')->where('last_name', 'Santos')->firstOrFail();

        $this->assertSame(['Healthy Teeth'], $senior->dental_concern);
    }

    #[Test]
    public function problems_needs_others_free_text_still_works_after_sanitizing(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('problemsNeeds', ['Limited problems encountered', 'Others'])
            ->set('problemsNeedsOther', 'roof leak')
            ->call('save')
            ->assertSet('saved', true);

        $senior = SeniorCitizen::where('first_name', 'Maria')->where('last_name', 'Santos')->firstOrFail();

        $this->assertSame(['Others: roof leak'], $senior->problems_needs);
    }

    #[Test]
    public function save_draft_sanitizes_and_round_trips_on_remount(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('healthcareDifficulty', ['Healthcare is accessible', 'Long waiting time'])
            ->call('saveDraft');

        $draft = ProfileDraft::whereNull('senior_citizen_id')
            ->where('created_by', $this->admin->id)
            ->latest()
            ->firstOrFail();

        $this->assertSame(['Long waiting time'], $draft->data['healthcareDifficulty']);
        // Untouched groups keep their create-defaults in the draft snapshot.
        $this->assertSame(['Physically Healthy'], $draft->data['medicalConcern']);

        Livewire::test(ProfileSurvey::class)
            ->assertSet('healthcareDifficulty', ['Long waiting time'])
            ->assertSet('medicalConcern', ['Physically Healthy']);
    }
}
