<?php

namespace Tests\Feature;

use App\Livewire\Surveys\ProfileSurvey;
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
 * Covers the admin-entered official OSCA-ID field added to ProfileSurvey,
 * distinct from the system-generated `osca_id` (displayed as "System ID").
 * The official OSCA-ID is optional and starts blank ("Unassigned") since
 * most seniors don't have a real OSCA-ID on file yet.
 */
class OfficialOscaIdTest extends TestCase
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
            ['email' => 'official-osca-id-admin@osca.local'],
            ['name' => 'Official OSCA ID Admin', 'password' => Hash::make('password')]
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
    public function official_osca_id_left_blank_on_create_saves_as_null(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $senior = SeniorCitizen::where('first_name', 'Maria')->where('last_name', 'Santos')->firstOrFail();
        $this->assertNull($senior->official_osca_id);
        $this->assertSame('Unassigned', $senior->official_osca_id_display);
    }

    #[Test]
    public function official_osca_id_provided_on_create_is_saved(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('officialOscaId', '12-3456-789')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $senior = SeniorCitizen::where('first_name', 'Maria')->where('last_name', 'Santos')->firstOrFail();
        $this->assertSame('12-3456-789', $senior->official_osca_id);
        $this->assertSame('12-3456-789', $senior->official_osca_id_display);
    }

    #[Test]
    public function official_osca_id_of_literal_zero_is_saved_not_discarded(): void
    {
        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('officialOscaId', '0')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $senior = SeniorCitizen::where('first_name', 'Maria')->where('last_name', 'Santos')->firstOrFail();
        $this->assertSame('0', $senior->official_osca_id);
        $this->assertSame('0', $senior->official_osca_id_display);
    }

    #[Test]
    public function official_osca_id_can_be_assigned_later_via_edit(): void
    {
        $senior = SeniorCitizen::create([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
        ]);
        $this->assertNull($senior->official_osca_id);

        Livewire::test(ProfileSurvey::class, ['seniorId' => $senior->id])
            ->assertSet('officialOscaId', '')
            ->set('officialOscaId', '98-7654-321')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $senior->refresh();
        $this->assertSame('98-7654-321', $senior->official_osca_id);
    }

    #[Test]
    public function duplicate_official_osca_id_is_rejected(): void
    {
        SeniorCitizen::create([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'official_osca_id' => '11-1111-111',
            'first_name' => 'Existing',
            'last_name' => 'Senior',
            'barangay' => 'Anibong',
            'date_of_birth' => '1945-01-01',
            'household_size' => 1,
        ]);

        $this->fillRequired(Livewire::test(ProfileSurvey::class))
            ->set('officialOscaId', '11-1111-111')
            ->call('save')
            ->assertHasErrors(['officialOscaId']);

        $this->assertDatabaseMissing('senior_citizens', ['first_name' => 'Maria', 'last_name' => 'Santos']);
    }

    #[Test]
    public function editing_a_senior_without_changing_their_own_official_osca_id_does_not_trigger_uniqueness_error(): void
    {
        $senior = SeniorCitizen::create([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'official_osca_id' => '22-2222-222',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
        ]);

        Livewire::test(ProfileSurvey::class, ['seniorId' => $senior->id])
            ->assertSet('officialOscaId', '22-2222-222')
            ->set('contactNumber', '09171234567')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $senior->refresh();
        $this->assertSame('22-2222-222', $senior->official_osca_id);
        $this->assertSame('09171234567', $senior->contact_number);
    }
}
