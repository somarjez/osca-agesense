<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression insurance: proves CSRF protection is genuinely active on a
 * real state-changing route, rather than merely asserting a config value.
 *
 * IMPORTANT CAVEAT ABOUT LARAVEL'S TEST HARNESS:
 * Illuminate\Foundation\Http\Middleware\VerifyCsrfToken (the base class
 * behind Illuminate\Foundation\Http\Middleware\ValidateCsrfToken used in
 * Laravel 11's bootstrap/app.php) contains a built-in bypass:
 *
 *     if ($this->isReading($request) ||
 *         $this->runningUnitTests() ||
 *         $this->inExceptArray($request) ||
 *         $this->tokensMatch($request)) { ... let it through ... }
 *
 * where runningUnitTests() returns
 *     $this->app->runningInConsole() && $this->app->runningUnitTests()
 *
 * Under `php artisan test` / phpunit, PHP_SAPI is 'cli' (runningInConsole()
 * = true) and phpunit.xml sets APP_ENV=testing, so
 * Application::runningUnitTests() (`$this->bound('env') && $this['env']
 * === 'testing'`) is also true. That means CSRF verification is silently
 * SKIPPED for every ordinary Feature test in this suite — which is exactly
 * why $this->post()/actingAs() elsewhere never need a CSRF token, and why a
 * naive "just POST without a token and expect 419" test here would pass
 * even if CSRF were completely broken (false confidence).
 *
 * To actually exercise the real CSRF code path, this test flips the
 * container's 'env' binding away from "testing" for the duration of the
 * test only. That collapses runningUnitTests() to false, disabling the
 * bypass, so the middleware falls through to real token verification.
 * Illuminate\Foundation\Testing\TestCase rebuilds the application fresh in
 * setUp() for every test method, so this mutation cannot leak into other
 * tests in the suite.
 */
class CsrfProtectionTest extends TestCase
{
    #[Test]
    public function post_without_valid_csrf_token_is_rejected_with_419_when_bypass_is_disabled(): void
    {
        // Disable the "we're running unit tests" CSRF bypass for this test only.
        $this->app['env'] = 'local';

        $response = $this->post('/login', [
            'email' => 'nobody@example.com',
            'password' => 'irrelevant-password',
        ]);

        $response->assertStatus(419);
    }

    #[Test]
    public function control_the_same_request_is_not_419_under_normal_test_env(): void
    {
        // Companion/control assertion proving the test above is meaningful:
        // under the suite's normal APP_ENV=testing, Laravel's own CSRF
        // middleware bypass is active, so the identical request (still no
        // _token) sails past CSRF entirely and reaches route/validation
        // logic instead (redirected back with a validation error, 302 —
        // never a 419). If this ever starts returning 419, the bypass
        // assumption above no longer holds and the primary test's rationale
        // needs to be revisited.
        $response = $this->post('/login', [
            'email' => 'nobody@example.com',
            'password' => 'irrelevant-password',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('email');
    }
}
