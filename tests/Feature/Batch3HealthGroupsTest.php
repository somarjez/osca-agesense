<?php

namespace Tests\Feature;

use App\Models\ClusterSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Batch3HealthGroupsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $encoder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin@osca.local'],
            ['name' => 'OSCA Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);

        $this->encoder = User::firstOrCreate(
            ['email' => 'encoder@osca.local'],
            ['name' => 'OSCA Encoder', 'password' => Hash::make('password')]
        );
        $this->encoder->syncRoles(['encoder']);
    }

    private function makeSnapshotForDate(string $date): void
    {
        foreach ([1, 2, 3, 4] as $cid) {
            ClusterSnapshot::create([
                'snapshot_date' => $date,
                'cluster_id' => $cid,
                'cluster_name' => "Group {$cid}",
                'member_count' => 10,
                'avg_composite_risk' => 0.4,
            ]);
        }
    }

    #[Test]
    public function health_groups_page_renders_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.cluster'))
            ->assertOk()
            ->assertSee('Model Insights')
            ->assertSee('Profile Group Explorer')
            ->assertSee('Snapshot History');
    }

    #[Test]
    public function model_insights_uses_corrected_who_domain_labels(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.cluster'))
            ->assertOk()
            ->assertSee('Intrinsic Capacity (IC)')
            ->assertSee('Functional Ability')
            ->assertDontSee('Daily Functioning')
            ->assertDontSee('Physical Capacity (IC)');
    }

    #[Test]
    public function admin_can_permanently_delete_a_snapshot_by_date(): void
    {
        $date = now()->subDay()->toDateString();
        $this->makeSnapshotForDate($date);
        $this->assertSame(4, ClusterSnapshot::whereDate('snapshot_date', $date)->count());

        $this->actingAs($this->admin)
            ->delete(route('reports.cluster.snapshot.destroy', $date))
            ->assertRedirect();

        $this->assertSame(0, ClusterSnapshot::whereDate('snapshot_date', $date)->count());
    }

    #[Test]
    public function encoder_cannot_delete_a_snapshot(): void
    {
        $date = now()->subDay()->toDateString();
        $this->makeSnapshotForDate($date);

        $this->actingAs($this->encoder)
            ->delete(route('reports.cluster.snapshot.destroy', $date))
            ->assertForbidden();

        $this->assertSame(4, ClusterSnapshot::whereDate('snapshot_date', $date)->count());
    }

    #[Test]
    public function deleting_an_unknown_snapshot_date_reports_no_match(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('reports.cluster.snapshot.destroy', '2099-01-01'))
            ->assertRedirect();
    }
}
