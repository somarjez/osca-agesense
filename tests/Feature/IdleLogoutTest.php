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
 * Idle auto-logout (see config/auth.php's idle_logout_minutes doc block).
 *
 * This is deliberately NOT the same feature as single_session_idle_minutes /
 * App\Support\SingleSession, which only gates whether a NEW login elsewhere
 * may reclaim the account — it never signs out the person already at the
 * keyboard. The actual idle timer lives client-side in
 * resources/js/idle-logout.js (an Alpine component mounted via
 * <x-idle-warning /> in layouts/app.blade.php) and has no server-side
 * equivalent to test directly; what IS testable server-side is the contract
 * it depends on: POST /logout accepts an optional reason=inactivity and
 * flashes a status message only in that case, and the authenticated layout
 * renders the configured thresholds for the JS timer to read.
 */
class IdleLogoutTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->user = User::firstOrCreate(
            ['email' => 'idle-logout@osca.local'],
            ['name' => 'Idle Logout Tester', 'password' => Hash::make('password')]
        );
        $this->user->syncRoles(['viewer']);
    }

    #[Test]
    public function logout_with_inactivity_reason_flashes_a_status_message(): void
    {
        $this->actingAs($this->user)
            ->post('/logout', ['reason' => 'inactivity'])
            ->assertRedirect('/login');

        $this->assertEquals(
            'You were signed out due to inactivity.',
            session('status')
        );
    }

    #[Test]
    public function normal_logout_flashes_no_status_message(): void
    {
        $this->actingAs($this->user)
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertNull(session('status'));
    }

    #[Test]
    public function login_page_renders_the_inactivity_notice_when_flashed(): void
    {
        $this->actingAs($this->user)->post('/logout', ['reason' => 'inactivity']);

        $this->get('/login')
            ->assertSee('You were signed out due to inactivity.');
    }

    #[Test]
    public function login_page_renders_no_notice_without_a_flash(): void
    {
        $this->get('/login')
            ->assertDontSee('You were signed out due to inactivity.');
    }

    #[Test]
    public function authenticated_layout_renders_the_configured_idle_thresholds(): void
    {
        $this->actingAs($this->user)
            ->get(route('dashboard'))
            ->assertSee('data-idle-minutes="15"', false)
            ->assertSee('data-warning-seconds="60"', false);
    }

    #[Test]
    public function expired_session_page_links_back_to_login(): void
    {
        // Directly render the 419 view, same as bootstrap/app.php's exception
        // renderer does for a real TokenMismatchException (see ErrorPagesTest
        // for the documented, verified mechanism this mirrors) — its "Sign in
        // again" CTA replaces the layout's default "Back to Dashboard" link,
        // which would otherwise bounce a guest straight back to /login anyway
        // but without explaining why.
        $view = view('errors.419')->render();

        $this->assertStringContainsString(route('login'), $view);
        $this->assertStringContainsString('Sign in again', $view);
    }
}
