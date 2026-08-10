<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Coverage for the app's entry point, `GET /` — previously untested (see
 * docs/plans front-door-polish). `/` is a single auth-aware redirect
 * (routes/web.php) so guests reach /login in one hop instead of bouncing
 * through /dashboard first; `/login` itself must stay out of search indexes.
 */
class FrontDoorTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    #[Test]
    public function guest_root_redirects_straight_to_login(): void
    {
        $this->get('/')
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function authenticated_root_redirects_to_dashboard(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'front-door-staff@osca.local'],
            ['name' => 'Front Door Staff', 'password' => Hash::make('password')]
        );
        $user->syncRoles(['viewer']);

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }

    #[Test]
    public function login_page_renders_for_guests(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertViewIs('auth.login');
    }

    #[Test]
    public function login_page_is_not_indexable(): void
    {
        $this->get(route('login'))
            ->assertSee('noindex, nofollow', false);
    }
}
