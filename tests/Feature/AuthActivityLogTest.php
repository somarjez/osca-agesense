<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for TC-DEP-06 (audit finding): login, logout, and
 * failed-login attempts were not captured in the audit trail at all — no
 * Illuminate\Auth\Events\Login/Logout/Failed listener existed anywhere in
 * the codebase. See App\Listeners\LogAuthenticationActivity, auto-discovered
 * by Laravel (deliberately NOT also registered via Event::listen() in
 * AppServiceProvider — an earlier version of this fix did both, which
 * doesn't override auto-discovery but stacks with it: every one of these
 * events was logged TWICE per real request, caught during the post-fix
 * re-verification pass via `php artisan event:list` showing two listener
 * entries per event. Every test below asserts an EXACT count for this
 * reason, not just assertDatabaseHas(), so that regression can't return
 * silently.
 */
class AuthActivityLogTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        User::create([
            'name' => 'AuthLog Target',
            'email' => 'authlog@osca.local',
            'password' => Hash::make('correct-password'),
        ]);
    }

    #[Test]
    public function successful_login_is_recorded_with_the_user_as_subject(): void
    {
        $user = User::where('email', 'authlog@osca.local')->firstOrFail();

        $this->post('/login', [
            'email' => 'authlog@osca.local',
            'password' => 'correct-password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'login',
            'user_id' => $user->id,
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);
        $this->assertSame(1, ActivityLog::where('action', 'login')->where('user_id', $user->id)->count(),
            'Exactly one login row must be written per real login request.');
    }

    #[Test]
    public function logout_is_recorded(): void
    {
        $user = User::where('email', 'authlog@osca.local')->firstOrFail();
        $this->actingAs($user);

        $this->post('/logout')->assertRedirect('/login');

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'logout',
            'user_id' => $user->id,
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);
        $this->assertSame(1, ActivityLog::where('action', 'logout')->where('user_id', $user->id)->count(),
            'Exactly one logout row must be written per real logout request.');
    }

    #[Test]
    public function failed_login_against_a_real_account_is_recorded_with_that_user_as_subject(): void
    {
        $user = User::where('email', 'authlog@osca.local')->firstOrFail();

        $this->post('/login', [
            'email' => 'authlog@osca.local',
            'password' => 'definitely-wrong',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'login_failed',
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);
        // A failed attempt is never itself "logged in as" anyone.
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'login_failed',
            'user_id' => null,
        ]);
        $this->assertSame(1, ActivityLog::where('action', 'login_failed')->where('subject_id', $user->id)->count(),
            'Exactly one login_failed row must be written per real failed request.');
    }

    #[Test]
    public function failed_login_against_a_nonexistent_email_is_recorded_with_no_subject(): void
    {
        // TC-DEP-06's actual hard case: subject_type/subject_id had to become
        // nullable (see the accompanying migration) for exactly this — an
        // attempted email that resolves no User at all.
        $this->post('/login', [
            'email' => 'nobody-registered@osca.local',
            'password' => 'whatever',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'login_failed',
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $matching = ActivityLog::where('action', 'login_failed')
            ->where('description', 'like', '%nobody-registered@osca.local%')
            ->get();
        $this->assertCount(1, $matching, 'Exactly one login_failed row must be written per real failed request.');
        $this->assertStringContainsString('nobody-registered@osca.local', $matching->first()->description);
    }
}
