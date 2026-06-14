<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Batch4GisAnalyticsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

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
    }

    #[Test]
    public function gis_geocode_uses_modal_not_native_confirm(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.gis'))
            ->assertOk()
            ->assertDontSee("onsubmit=\"return confirm('Run bulk", false)
            ->assertSee('Run bulk geocoding?');
    }

    #[Test]
    public function cluster_filter_labels_use_full_cluster_titles(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.gis'))
            ->assertOk()
            ->assertSee('C1 · High Functioning / Well-Supported Seniors')
            ->assertSee('C2 · Stable Ageing / Moderate Support Needs')
            ->assertSee('C3 · Environmentally and Financially Vulnerable Seniors')
            ->assertSee('C4 · Low Functioning / Multi-Domain Priority Seniors');
    }

    #[Test]
    public function facility_markers_remain_clickable_and_delegate_true_overlaps_to_seniors(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.gis'))
            ->assertOk()
            ->assertSee("map.getPane('gis-facility-pane').style.zIndex = 650;", false)
            ->assertSee("map.getPane('gis-senior-pane').style.zIndex = 620;", false)
            ->assertSee('function visibleSeniorLayerAtClick(map, event)', false)
            ->assertSee('const seniorLayer = visibleSeniorLayerAtClick(map, event);', false)
            ->assertSee("seniorLayer.fire('click'", false)
            ->assertSee("const name = escapeHtml(p.name ?? 'N/A');", false)
            ->assertSee("const type = escapeHtml(p.type ?? 'N/A');", false)
            ->assertSee("const barangay = escapeHtml(p.barangay ?? 'N/A');", false)
            ->assertSee("const source = escapeHtml(p.source ?? 'N/A');", false);
    }
}
