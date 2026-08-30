<?php

namespace Tests\Feature;

use App\Livewire\Surveys\ProfileSurvey;
use App\Models\SeniorCitizen;
use App\Models\User;
use App\Support\SeniorDataVersion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Deceased-status lifecycle: SeniorCitizen::scopeDeceased()/scopeActive(),
 * the seniors.deceased roster page, the ProfileSurvey edit-form status
 * control (with audit stamp + reactivation clearing), and the
 * SeniorDataVersion cache bump on status change.
 */
class DeceasedStatusTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::firstOrCreate(
            ['email' => 'deceased-admin@osca.local'],
            ['name' => 'Deceased Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);

        $this->viewer = User::firstOrCreate(
            ['email' => 'deceased-viewer@osca.local'],
            ['name' => 'Deceased Viewer', 'password' => Hash::make('password')]
        );
        $this->viewer->syncRoles(['viewer']);
    }

    private function makeSenior(array $overrides = []): SeniorCitizen
    {
        return SeniorCitizen::create(array_merge([
            'osca_id' => 'TST-'.uniqid(),
            'first_name' => 'Deceased',
            'last_name' => 'TestSenior',
            'barangay' => 'Anibong',
            'date_of_birth' => '1945-03-10',
            'gender' => 'Male',
            'marital_status' => 'Widowed',
            'child_financial_support' => 'Yes',
            'spouse_working' => 'N/A',
            'household_size' => 1,
            'num_children' => 2,
            'num_working_children' => 0,
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
            'status' => 'active',
            'encoded_by' => 'Test',
        ], $overrides));
    }

    private function fillRequired($component)
    {
        return $component
            ->set('firstName', 'Brand')
            ->set('lastName', 'NewSenior')
            ->set('barangay', 'Anibong')
            ->set('dateOfBirth', '1950-01-01')
            ->set('gender', 'Female')
            ->set('maritalStatus', 'Widowed')
            ->set('numChildren', 2)
            ->set('childFinancialSupport', 'Yes')
            ->set('spouseWorking', 'N/A')
            ->set('educationalAttainment', 'High School Graduate')
            ->set('livingWith', ['Children'])
            ->set('monthlyIncomeRange', '5,000 - 10,000');
    }

    // ── Model scopes ─────────────────────────────────────────────────────────

    #[Test]
    public function scope_deceased_returns_only_deceased_seniors(): void
    {
        $active = $this->makeSenior(['first_name' => 'Alive']);
        $deceased = $this->makeSenior(['first_name' => 'Passed', 'status' => 'deceased']);

        $ids = SeniorCitizen::deceased()->pluck('id');

        $this->assertTrue($ids->contains($deceased->id));
        $this->assertFalse($ids->contains($active->id));
    }

    #[Test]
    public function scope_active_excludes_deceased_seniors(): void
    {
        $active = $this->makeSenior(['first_name' => 'Alive']);
        $deceased = $this->makeSenior(['first_name' => 'Passed', 'status' => 'deceased']);

        $ids = SeniorCitizen::active()->pluck('id');

        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($deceased->id));
    }

    // ── Deceased Seniors page ───────────────────────────────────────────────

    #[Test]
    public function deceased_index_lists_only_deceased_seniors(): void
    {
        $active = $this->makeSenior(['first_name' => 'Alive']);
        $deceased = $this->makeSenior(['first_name' => 'Passed', 'status' => 'deceased']);

        $response = $this->actingAs($this->admin)->get(route('seniors.deceased'));

        $response->assertOk();
        $response->assertSee($deceased->full_name);
        $response->assertDontSee($active->full_name);
    }

    #[Test]
    public function active_index_excludes_deceased_seniors(): void
    {
        $active = $this->makeSenior(['first_name' => 'Alive']);
        $deceased = $this->makeSenior(['first_name' => 'Passed', 'status' => 'deceased']);

        $response = $this->actingAs($this->admin)->get(route('seniors.index'));

        $response->assertOk();
        $response->assertSee($active->full_name);
        $response->assertDontSee($deceased->full_name);
    }

    #[Test]
    public function viewer_role_can_view_deceased_roster(): void
    {
        $this->actingAs($this->viewer)
            ->get(route('seniors.deceased'))
            ->assertOk();
    }

    // ── ProfileSurvey edit-form status control ──────────────────────────────

    #[Test]
    public function marking_a_senior_deceased_persists_status_and_death_fields(): void
    {
        $senior = $this->makeSenior();

        $this->actingAs($this->admin);
        Livewire::test(ProfileSurvey::class, ['seniorId' => $senior->id])
            ->set('status', 'deceased')
            ->set('dateOfDeath', '2026-06-01')
            ->set('deceasedNote', 'Passed peacefully.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $senior->refresh();
        $this->assertSame('deceased', $senior->status);
        $this->assertSame('2026-06-01', $senior->date_of_death->format('Y-m-d'));
        $this->assertSame('Passed peacefully.', $senior->deceased_note);
        $this->assertSame($this->admin->name, $senior->status_changed_by);
        $this->assertNotNull($senior->status_changed_at);
    }

    #[Test]
    public function marking_a_senior_deceased_requires_a_date_of_death(): void
    {
        $senior = $this->makeSenior();

        $this->actingAs($this->admin);
        Livewire::test(ProfileSurvey::class, ['seniorId' => $senior->id])
            ->set('status', 'deceased')
            ->set('dateOfDeath', '')
            ->call('save')
            ->assertHasErrors(['dateOfDeath'])
            ->assertSet('saved', false);

        $this->assertSame('active', $senior->fresh()->status);
    }

    #[Test]
    public function reactivating_a_deceased_senior_clears_death_fields(): void
    {
        $senior = $this->makeSenior([
            'status' => 'deceased',
            'date_of_death' => '2026-01-01',
            'deceased_note' => 'Original note',
            'status_changed_by' => 'Someone',
            'status_changed_at' => now()->subDay(),
        ]);

        $this->actingAs($this->admin);
        Livewire::test(ProfileSurvey::class, ['seniorId' => $senior->id])
            ->assertSet('status', 'deceased')
            ->set('status', 'active')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $senior->refresh();
        $this->assertSame('active', $senior->status);
        $this->assertNull($senior->date_of_death);
        $this->assertNull($senior->deceased_note);
        $this->assertSame($this->admin->name, $senior->status_changed_by);
    }

    #[Test]
    public function unchanged_status_on_edit_does_not_touch_audit_fields(): void
    {
        $senior = $this->makeSenior([
            'status' => 'active',
            'status_changed_by' => 'Original Encoder',
            'status_changed_at' => now()->subWeek(),
        ]);
        $originalChangedAt = $senior->status_changed_at;

        $this->actingAs($this->admin);
        Livewire::test(ProfileSurvey::class, ['seniorId' => $senior->id])
            ->set('contactNumber', '09171234567')
            ->call('save')
            ->assertHasNoErrors();

        $senior->refresh();
        $this->assertSame('Original Encoder', $senior->status_changed_by);
        $this->assertTrue($originalChangedAt->equalTo($senior->status_changed_at));
    }

    #[Test]
    public function new_profile_always_saves_as_active(): void
    {
        $this->actingAs($this->admin);
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $senior = SeniorCitizen::where('first_name', 'Brand')->where('last_name', 'NewSenior')->firstOrFail();
        $this->assertSame('active', $senior->status);
        $this->assertNull($senior->status_changed_by);
        $this->assertNull($senior->status_changed_at);
    }

    // ── SeniorDataVersion cache bump ────────────────────────────────────────

    #[Test]
    public function marking_deceased_bumps_senior_data_version(): void
    {
        $senior = $this->makeSenior();
        $before = SeniorDataVersion::current();

        // bump() stores now()->timestamp (second resolution) — force a
        // distinct second so this update()'s bump is provably visible rather
        // than coincidentally re-storing the same value makeSenior()'s
        // creation already bumped to.
        sleep(1);
        $senior->update(['status' => 'deceased']);

        $this->assertGreaterThan($before, SeniorDataVersion::current());
    }

    #[Test]
    public function unrelated_field_edit_does_not_bump_senior_data_version(): void
    {
        $senior = $this->makeSenior();
        SeniorDataVersion::bump();
        $before = SeniorDataVersion::current();

        // Force distinct timestamp resolution vs bump() above, which stores
        // now()->timestamp — a same-second update() must not appear to bump.
        sleep(1);
        $senior->update(['contact_number' => '09171234567']);

        $this->assertSame($before, SeniorDataVersion::current());
    }
}
