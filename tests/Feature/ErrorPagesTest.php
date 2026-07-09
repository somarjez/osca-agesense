<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 9: proves bootstrap/app.php's withExceptions() closure + the
 * resources/views/errors/{403,404,500}.blade.php views work, and pins down
 * the exact (verified, not assumed) behavior of APP_DEBUG in Laravel 11.
 *
 * IMPORTANT — verified against Laravel 11's own Handler source
 * (vendor/laravel/framework/.../Foundation/Exceptions/Handler.php):
 *
 * Laravel auto-discovers resources/views/errors/{status}.blade.php via a
 * built-in `errors::` namespace and, for any exception that is ALREADY an
 * HttpExceptionInterface (403 abort(), 404 route-model-binding miss, 419
 * CSRF mismatch, 503 maintenance mode), renders that view UNCONDITIONALLY —
 * regardless of APP_DEBUG. That's deliberate, documented Laravel behavior,
 * not a bug: those are intentional aborts, not stack-trace leaks. So the
 * custom 403/404/419/503 pages render the same way whether APP_DEBUG is
 * true or false, in this suite and in real local dev alike.
 *
 * APP_DEBUG only changes behavior for a genuine *uncaught, non-HTTP*
 * exception that would become a 500: Laravel's Handler::prepareResponse()
 * shows the full debug trace only when `!isHttpException($e) &&
 * config('app.debug')`. That is the one path this task's "zero effect on
 * local dev" safety-net requirement actually applies to, and it's what the
 * debug-gated 500 tests below prove.
 */
class ErrorPagesTest extends TestCase
{
    use DatabaseTransactions;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->viewer = User::firstOrCreate(
            ['email' => 'error-pages-viewer@osca.local'],
            ['name' => 'Error Pages Viewer', 'password' => Hash::make('password')]
        );
        $this->viewer->syncRoles(['viewer']);
    }

    #[Test]
    public function debug_false_renders_custom_404_view_for_nonexistent_senior(): void
    {
        Config::set('app.debug', false);

        $response = $this->actingAs($this->viewer)
            ->get('/seniors/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
        $response->assertSeeText('Page Not Found');
    }

    #[Test]
    public function debug_false_renders_custom_404_view_for_undefined_route(): void
    {
        Config::set('app.debug', false);

        $response = $this->actingAs($this->viewer)->get('/this-route-does-not-exist');

        $response->assertStatus(404);
        $response->assertSeeText('Page Not Found');
    }

    #[Test]
    public function debug_false_renders_custom_403_view_for_unauthorized_role(): void
    {
        Config::set('app.debug', false);

        // /users is role:admin only (routes/users.php) — a viewer is authenticated
        // but not authorized, which is exactly the RoleMiddleware::abort(403, ...)
        // path Task 2 relies on throughout the app.
        $response = $this->actingAs($this->viewer)->get('/users');

        $response->assertStatus(403);
        $response->assertSeeText('Access Denied');
    }

    #[Test]
    public function debug_true_still_renders_custom_404_view_for_intentional_aborts(): void
    {
        // This is the suite's normal state (.env has APP_DEBUG=true and nothing in
        // phpunit.xml overrides it). Unlike a genuine uncaught 500, a 404 raised
        // via route-model-binding is already an HttpException by the time it
        // reaches Laravel's Handler, so — per the class doc-block above — Laravel
        // renders our custom view here too, regardless of debug. Documented here
        // so this doesn't read as a bug on future re-inspection.
        Config::set('app.debug', true);

        $response = $this->actingAs($this->viewer)
            ->get('/seniors/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
        $response->assertSeeText('Page Not Found');
    }

    #[Test]
    public function debug_true_still_renders_custom_403_view_for_intentional_aborts(): void
    {
        Config::set('app.debug', true);

        $response = $this->actingAs($this->viewer)->get('/users');

        $response->assertStatus(403);
        $response->assertSeeText('Access Denied');
    }

    #[Test]
    public function debug_false_renders_custom_500_view_for_uncaught_exception(): void
    {
        Config::set('app.debug', false);
        $this->registerThrowingRoute();

        $response = $this->get('/__task9-throws-500');

        $response->assertStatus(500);
        $response->assertSeeText('Something Went Wrong');
    }

    #[Test]
    public function debug_true_leaves_laravels_default_500_rendering_untouched(): void
    {
        // This is the actual "zero effect on local dev" guarantee: a genuine
        // uncaught, non-HTTP exception must still show Laravel's real debug
        // trace when APP_DEBUG=true, so developers can diagnose it.
        Config::set('app.debug', true);
        $this->registerThrowingRoute();

        $response = $this->get('/__task9-throws-500');

        $response->assertStatus(500);

        // Deliberately NOT a text-content assertion: Laravel's own debug
        // renderer shows source-code context for every stack frame, and
        // since this request runs in-process, that includes THIS test
        // method's own source — which literally contains the string
        // "Something Went Wrong" as the (correct) assertion below used to
        // check for. That made the naive assertDontSee('Something Went
        // Wrong') self-referentially fail: not because the custom view
        // rendered, but because the debug trace echoed this very call site.
        // Asserting on the rendering mechanism itself (was our
        // errors.500 Blade view used, or not) is what this test actually
        // needs to prove, and isn't fooled by incidental string matches.
        $this->assertFalse(
            $response->original instanceof \Illuminate\View\View
                && $response->original->getName() === 'errors.500',
            'Expected debug=true to bypass the custom errors.500 view and leave '.
            'Laravel\'s own debug rendering untouched.'
        );
    }

    private function registerThrowingRoute(): void
    {
        Route::get('/__task9-throws-500', function () {
            throw new \RuntimeException('Task 9 test: simulated unexpected error.');
        })->middleware('web');
    }
}
