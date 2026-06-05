<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NewProfileModalTest extends TestCase
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

    #[Test]
    public function seniors_index_embeds_the_registration_wizard_in_a_modal(): void
    {
        $this->actingAs($this->admin)
            ->get(route('seniors.index'))
            ->assertOk()
            ->assertSee('Register New Senior Citizen')   // modal header
            ->assertSee('newProfileOpen = true', false)  // trigger wired to the modal
            ->assertSee('I. Identifying Information');    // the wizard's first step renders inline
    }

    #[Test]
    public function seniors_index_with_new_param_auto_opens_the_modal(): void
    {
        $this->actingAs($this->admin)
            ->get(route('seniors.index', ['new' => 1]))
            ->assertOk()
            ->assertSee('newProfileOpen: true', false);  // Alpine state initialized open
    }
}
