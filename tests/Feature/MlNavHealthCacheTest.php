<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers the fix for "the Services status dot stays red after services are
 * already back up until the page is manually refreshed":
 *
 * 1. /ml/nav-health's server-side cache TTL was cut from 15s to 5s while
 *    services are down/degraded (30s while up is unchanged), since the
 *    client-side poller (resources/js/ml-health.js) now checks every 10s
 *    while down — a longer server cache would just make it wait on stale
 *    data anyway.
 * 2. MlController::wake()/wakeStatus() now forget the ml_nav_health cache
 *    entry the instant services are confirmed reachable, so a client that
 *    polls right after a successful wake doesn't get served a leftover
 *    "unreachable" result for the rest of that entry's TTL.
 */
class MlNavHealthCacheTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.python.preprocess_url' => 'https://preprocess.test']);
        config(['services.python.inference_url' => 'https://inference.test']);

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::firstOrCreate(
            ['email' => 'mlnavhealth-admin@osca.local'],
            ['name' => 'MlNavHealth Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);
        $this->actingAs($this->admin);

        Cache::forget('ml_nav_health');
    }

    // $this->travel() (used below to exercise the TTL boundary) sets
    // Carbon::setTestNow() globally and does NOT reset it automatically —
    // left unset, it silently shifted every other test that runs later in
    // the same process (e.g. now()->subDay() calculations in unrelated
    // report tests), so it must be cleared here.
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function down_status_is_cached_for_only_five_seconds(): void
    {
        Http::fake(['*/health' => Http::response('', 503)]);

        // Not asserting the exact dot value ('err' vs 'warn') — that depends
        // on whether this environment's local Python fallback is available,
        // which is beside the point here. What matters is it isn't 'ok', and
        // that the TTL that governs how long that non-ok result is cached is
        // the shortened one.
        $this->getJson(route('ml.nav-health'))->assertJsonMissing(['dot' => 'ok']);
        Http::assertSentCount(2); // one healthCheck() = one pooled GET per service

        // Still within the 5s TTL — served from cache, no new HTTP calls.
        $this->travel(4)->seconds();
        $this->getJson(route('ml.nav-health'))->assertJsonMissing(['dot' => 'ok']);
        Http::assertSentCount(2);

        // Past the 5s TTL — cache miss, healthCheck() runs again.
        $this->travel(2)->seconds();
        $this->getJson(route('ml.nav-health'))->assertJsonMissing(['dot' => 'ok']);
        Http::assertSentCount(4);
    }

    #[Test]
    public function online_status_is_still_cached_for_thirty_seconds(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'ok'], 200)]);

        $this->getJson(route('ml.nav-health'))->assertJson([
            'dot' => 'ok',
            'services' => ['preprocessor' => 'ok', 'inference' => 'ok'],
        ]);
        Http::assertSentCount(2);

        // Within the 30s TTL — still cached.
        $this->travel(20)->seconds();
        $this->getJson(route('ml.nav-health'))->assertJson(['dot' => 'ok']);
        Http::assertSentCount(2);

        // Past the 30s TTL — refreshed.
        $this->travel(11)->seconds();
        $this->getJson(route('ml.nav-health'))->assertJson(['dot' => 'ok']);
        Http::assertSentCount(4);
    }

    #[Test]
    public function wake_forgets_the_nav_health_cache_once_services_are_ready(): void
    {
        // Seed a stale "unreachable" nav-health entry, as if /ml/nav-health
        // had been polled moments before the services actually came back.
        Cache::put('ml_nav_health', [
            'preprocessor' => 'unreachable',
            'inference' => 'unreachable',
            'local_runner' => 'unavailable',
            'mode' => 'php_fallback',
        ], 5);

        Http::fake(['*/health' => Http::response(['status' => 'ok'], 200)]);

        $resp = $this->postJson(route('ml.wake'));
        $resp->assertJson(['ready' => true]);

        $this->assertNull(Cache::get('ml_nav_health'));
    }

    #[Test]
    public function wake_does_not_forget_the_cache_when_still_cold(): void
    {
        Cache::put('ml_nav_health', ['preprocessor' => 'unreachable', 'inference' => 'unreachable', 'local_runner' => 'unavailable', 'mode' => 'php_fallback'], 5);

        Http::fake([
            '*:5001/health' => Http::response('', 503),
            '*:5002/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $this->postJson(route('ml.wake'))->assertJson(['ready' => false]);

        $this->assertNotNull(Cache::get('ml_nav_health'));
    }

    #[Test]
    public function wake_status_forgets_the_nav_health_cache_once_mode_is_http(): void
    {
        Cache::put('ml_nav_health', ['preprocessor' => 'unreachable', 'inference' => 'unreachable', 'local_runner' => 'unavailable', 'mode' => 'php_fallback'], 5);

        Http::fake(['*/health' => Http::response(['status' => 'ok'], 200)]);

        $this->getJson(route('ml.wake-status'))->assertJson(['mode' => 'http']);

        $this->assertNull(Cache::get('ml_nav_health'));
    }
}
