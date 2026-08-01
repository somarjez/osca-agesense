<?php

namespace Tests\Feature;

use App\Jobs\WakeMlServices;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The "Wake up ML services" action used to ping each Python service inline
 * with a 5s/3s timeout and return immediately — found in production to be
 * genuinely insufficient to wake a fully-cold Render free-tier container (a
 * real browser visiting the identical /health URL, with no such short
 * timeout, reliably woke it; the inline ping did not, repeatedly). It now
 * dispatches WakeMlServices onto the queue instead of running inline, since
 * MlService::pingToWake() waits patiently (up to a few minutes) for each
 * service to come up.
 */
class MlWakeTest extends TestCase
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
            ['email' => 'mlwake-admin@osca.local'],
            ['name' => 'MlWake Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);
        $this->actingAs($this->admin);
    }

    #[Test]
    public function wake_dispatches_a_queued_job_instead_of_pinging_inline(): void
    {
        Queue::fake();

        $resp = $this->postJson(route('ml.wake'));

        $resp->assertStatus(200);
        $resp->assertJson(['queued' => true]);
        Queue::assertPushed(WakeMlServices::class);
    }
}
