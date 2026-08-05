<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SeniorDataVersion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * /dashboard/version-check — the lightweight endpoint the dashboard's Alpine
 * poller hits every ~15-20s so it can trigger a real Livewire refresh the
 * moment ML results change, instead of waiting on the much wider
 * wire:poll.300s backstop. Just wraps SeniorDataVersion::current(); no DB
 * query of its own.
 */
class DashboardVersionCheckTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::firstOrCreate(
            ['email' => 'dashboard-version-admin@osca.local'],
            ['name' => 'Dashboard Version Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);
    }

    #[Test]
    public function returns_the_current_senior_data_version(): void
    {
        SeniorDataVersion::bump();
        $expected = SeniorDataVersion::current();

        $response = $this->actingAs($this->admin)->getJson(route('dashboard.version-check'));

        $response->assertOk()->assertExactJson(['version' => $expected]);
    }

    #[Test]
    public function reflects_a_new_version_after_bump(): void
    {
        SeniorDataVersion::bump();
        $before = $this->actingAs($this->admin)->getJson(route('dashboard.version-check'))->json('version');

        // bump() stamps the current timestamp — sleep 1s so a second bump()
        // produces a strictly different value, not just a same-second no-op.
        sleep(1);
        SeniorDataVersion::bump();
        $after = $this->actingAs($this->admin)->getJson(route('dashboard.version-check'))->json('version');

        $this->assertNotSame($before, $after);
    }

    #[Test]
    public function requires_authentication(): void
    {
        $this->getJson(route('dashboard.version-check'))->assertUnauthorized();
    }
}
