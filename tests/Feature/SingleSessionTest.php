<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SingleSession;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * One active session per account (see App\Support\SingleSession and the
 * POST /login closure in routes/auth.php).
 *
 * A login is blocked while the account is already signed in on another,
 * non-idle device. A session idle beyond auth.single_session_idle_minutes is
 * treated as stale and reclaimed by a fresh login, so a user who closed their
 * browser without signing out is never permanently locked out.
 *
 * "Another device" is simulated by inserting a row into the `sessions` table
 * directly, which is independent of the configured session driver.
 */
class SingleSessionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::create([
            'name' => 'Single Session Target',
            'email' => 'single@osca.local',
            'password' => Hash::make('correct-password'),
        ]);
        $user->syncRoles(['admin']);
        Cache::forget("single_session_attempt:{$user->id}");
    }

    private function user(): User
    {
        return User::where('email', 'single@osca.local')->firstOrFail();
    }

    /**
     * Insert a fake `sessions` row for a user to stand in for another device.
     */
    private function seedSession(int $userId, int $lastActivity): string
    {
        $id = (string) Str::uuid();

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'OtherDevice/1.0',
            'payload' => base64_encode(serialize([])),
            'last_activity' => $lastActivity,
        ]);

        return $id;
    }

    #[Test]
    public function login_is_blocked_when_the_account_is_active_on_another_device(): void
    {
        $this->seedSession($this->user()->id, time());

        $response = $this->post('/login', [
            'email' => 'single@osca.local',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->assertStringContainsString(
            'another device',
            session('errors')->get('email')[0]
        );
    }

    #[Test]
    public function login_is_allowed_and_reclaims_a_stale_idle_session(): void
    {
        // Idle for 30 minutes, well past the default 20-minute threshold.
        $staleId = $this->seedSession($this->user()->id, time() - (30 * 60));

        $response = $this->post('/login', [
            'email' => 'single@osca.local',
            'password' => 'correct-password',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();

        // The stale session row was reclaimed (deleted) on login.
        $this->assertFalse(
            DB::table('sessions')->where('id', $staleId)->exists()
        );
    }

    #[Test]
    public function login_succeeds_when_no_other_session_exists(): void
    {
        $response = $this->post('/login', [
            'email' => 'single@osca.local',
            'password' => 'correct-password',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
    }

    #[Test]
    public function wrong_password_returns_auth_failed_without_leaking_active_status(): void
    {
        // An active session exists, but a wrong password must NOT reveal it.
        $this->seedSession($this->user()->id, time());

        $response = $this->post('/login', [
            'email' => 'single@osca.local',
            'password' => 'definitely-wrong',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->assertStringNotContainsString(
            'another device',
            session('errors')->get('email')[0]
        );
    }

    #[Test]
    public function logging_out_frees_the_account_for_a_new_session(): void
    {
        $first = $this->post('/login', [
            'email' => 'single@osca.local',
            'password' => 'correct-password',
        ]);
        $first->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();

        $second = $this->post('/login', [
            'email' => 'single@osca.local',
            'password' => 'correct-password',
        ]);
        $second->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
    }

    #[Test]
    public function login_attempt_endpoint_requires_authentication(): void
    {
        $this->getJson(route('account.login-attempt-check'))->assertUnauthorized();
    }

    #[Test]
    public function login_attempt_is_user_scoped_and_read_once(): void
    {
        SingleSession::recordAttempt($this->user(), '203.0.113.8');

        $other = User::create([
            'name' => 'Other Session User',
            'email' => 'other-session@osca.local',
            'password' => Hash::make('correct-password'),
        ]);
        $other->syncRoles(['admin']);

        $this->actingAs($other)
            ->getJson(route('account.login-attempt-check'))
            ->assertOk()
            ->assertJsonPath('attempt', null);

        $this->actingAs($this->user())
            ->getJson(route('account.login-attempt-check'))
            ->assertOk()
            ->assertJsonPath('attempt.ip', '203.0.113.8');

        $this->getJson(route('account.login-attempt-check'))
            ->assertOk()
            ->assertJsonPath('attempt', null);
    }

    #[Test]
    public function login_attempt_survives_a_realistic_background_tab_delay(): void
    {
        SingleSession::recordAttempt($this->user(), '203.0.113.9');

        $this->travel(2)->minutes();

        $this->assertSame('203.0.113.9', SingleSession::pullAttempt($this->user()->id)['ip'] ?? null);
    }
}
