<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards Task 5: POST /login is throttled via RateLimiter::for('login', ...)
 * registered in AppServiceProvider::boot(), keyed on "{email}|{ip}" so that
 * neither a shared IP (e.g. NAT/office network) nor a single attacker
 * spraying many emails from one IP trips the *other* dimension's bucket.
 *
 * Note on cross-test pollution: Illuminate\Foundation\Testing\TestCase
 * rebuilds the whole application container fresh in setUp() for every test
 * method (see CsrfProtectionTest's docblock for the same observation), and
 * phpunit.xml sets CACHE_STORE=array. The array cache store's data lives in
 * a property on the store instance bound in that container, so a new
 * container per test method means a brand new, empty array store per test
 * method too - there is nothing to leak between methods here, and no
 * explicit RateLimiter::clear() is needed in tearDown(). This is verified
 * below by test_a_different_email_from_the_same_ip_is_not_blocked, which
 * would fail if state from the "same email" test leaked in ahead of it.
 */
class LoginRateLimitTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        User::create([
            'name' => 'Rate Limit Target',
            'email' => 'ratelimit@osca.local',
            'password' => Hash::make('correct-password'),
        ]);
    }

    #[Test]
    public function the_sixth_failed_login_attempt_within_a_minute_is_throttled(): void
    {
        $payload = [
            'email' => 'ratelimit@osca.local',
            'password' => 'definitely-wrong',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->post('/login', $payload);

            // Attempts 1-5 are allowed through to normal validation handling
            // (wrong credentials -> 302 redirect back with an email error),
            // proving the limit hasn't tripped early.
            $response->assertStatus(302);
            $response->assertSessionHasErrors('email');
        }

        $sixthResponse = $this->post('/login', $payload);

        $sixthResponse->assertStatus(429);
        $this->assertNotNull($sixthResponse->headers->get('Retry-After'));
    }

    #[Test]
    public function a_different_email_from_the_same_ip_is_not_blocked_by_the_first_emails_failures(): void
    {
        User::create([
            'name' => 'Other User',
            'email' => 'other@osca.local',
            'password' => Hash::make('correct-password'),
        ]);

        // Exhaust the limit for one email address, from the default test IP.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', [
                'email' => 'ratelimit@osca.local',
                'password' => 'definitely-wrong',
            ]);
        }
        $blocked = $this->post('/login', [
            'email' => 'ratelimit@osca.local',
            'password' => 'definitely-wrong',
        ]);
        $blocked->assertStatus(429);

        // A different email from the same IP still gets normal handling,
        // proving the limiter key includes email, not just IP.
        $otherEmailResponse = $this->post('/login', [
            'email' => 'other@osca.local',
            'password' => 'definitely-wrong',
        ]);

        $otherEmailResponse->assertStatus(302);
        $otherEmailResponse->assertSessionHasErrors('email');
    }

    #[Test]
    public function a_successful_login_within_the_limit_still_works(): void
    {
        $response = $this->post('/login', [
            'email' => 'ratelimit@osca.local',
            'password' => 'correct-password',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();
    }
}
