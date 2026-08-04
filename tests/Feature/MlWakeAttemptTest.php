<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MlService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * MlService::wakeAttempt() replaced the queued-job pingToWake() mechanism
 * (WakeMlServices) — the queued path was found unreliable in production, and
 * more importantly the client-side direct fetch() it was paired with only
 * works on a device whose network can reach *.onrender.com directly. This
 * exercises the bounded, server-side, concurrent (Http::pool()) wake attempt
 * and the /ml/wake route's response shape directly.
 */
class MlWakeAttemptTest extends TestCase
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
            ['email' => 'mlwakeattempt-admin@osca.local'],
            ['name' => 'MlWakeAttempt Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);
        $this->actingAs($this->admin);
    }

    #[Test]
    public function both_services_warm_reports_both_true(): void
    {
        Http::fake([
            'preprocess.test/health' => Http::response(['status' => 'ok'], 200),
            'inference.test/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $result = app(MlService::class)->wakeAttempt(budgetSeconds: 5);

        $this->assertSame(['preprocess' => true, 'inference' => true], $result);
    }

    #[Test]
    public function one_service_still_cold_reports_that_one_false(): void
    {
        Http::fake([
            'preprocess.test/health' => Http::response(['status' => 'booting'], 503),
            'inference.test/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $result = app(MlService::class)->wakeAttempt(budgetSeconds: 5);

        $this->assertSame(['preprocess' => false, 'inference' => true], $result);
    }

    #[Test]
    public function wake_route_returns_ready_true_when_both_services_are_warm(): void
    {
        Http::fake([
            'preprocess.test/health' => Http::response(['status' => 'ok'], 200),
            'inference.test/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $resp = $this->postJson(route('ml.wake'));

        $resp->assertStatus(200);
        $resp->assertJson(['preprocess' => true, 'inference' => true, 'ready' => true]);
    }

    #[Test]
    public function wake_route_returns_ready_false_when_a_service_is_still_cold(): void
    {
        Http::fake([
            'preprocess.test/health' => Http::response(['status' => 'booting'], 503),
            'inference.test/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $resp = $this->postJson(route('ml.wake'));

        $resp->assertStatus(200);
        $resp->assertJson(['preprocess' => false, 'inference' => true, 'ready' => false]);
    }
}
