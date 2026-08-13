<?php

namespace Tests\Feature;

use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Functional safety net for the case-insensitive search fix — see
 * SeniorCitizenSearchTermTest (tests/Unit) for the SQL-shape assertion that
 * actually proves cross-driver (Postgres) correctness; local MySQL's default
 * collation is already case-insensitive, so this test alone would have
 * passed even before the fix. It's here to prove the wiring end-to-end
 * (route -> controller -> scope), not the collation behavior itself.
 */
class SeniorSearchCaseInsensitiveTest extends TestCase
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
            ['email' => 'searchcase-admin@osca.local'],
            ['name' => 'SearchCase Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);
    }

    #[Test]
    public function lowercase_search_finds_a_mixed_case_stored_name(): void
    {
        $senior = SeniorCitizen::create([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => 'Juan',
            'last_name' => 'DelaCruzSearchCase',
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
        ]);

        $this->actingAs($this->admin)
            ->get(route('seniors.index', ['search' => 'delacruzsearchcase']))
            ->assertOk()
            ->assertSee($senior->osca_id);
    }

    #[Test]
    public function uppercase_search_finds_a_lowercase_stored_osca_id_component(): void
    {
        $senior = SeniorCitizen::create([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => 'SearchCaseOscaId',
            'last_name' => 'Test',
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
        ]);

        $this->actingAs($this->admin)
            ->get(route('seniors.index', ['search' => strtoupper($senior->osca_id)]))
            ->assertOk()
            ->assertSee($senior->osca_id);
    }
}
