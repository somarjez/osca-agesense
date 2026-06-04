<?php

namespace Tests\Feature;

use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Batch6AdminMiscTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $viewer;

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

        $this->viewer = User::firstOrCreate(
            ['email' => 'viewer@osca.local'],
            ['name' => 'OSCA Viewer', 'password' => Hash::make('password')]
        );
        $this->viewer->syncRoles(['viewer']);
    }

    /** A senior with a QoL survey (so it appears in the batch "eligible" list). */
    private function makeEligibleSenior(string $first, string $last): SeniorCitizen
    {
        $senior = SeniorCitizen::create([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => $first,
            'last_name' => $last,
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
        ]);

        QolSurvey::create([
            'senior_citizen_id' => $senior->id,
            'survey_date' => now()->format('Y-m-d'),
            'status' => 'processed',
        ]);

        return $senior;
    }

    #[Test]
    public function batch_analysis_search_filters_by_name(): void
    {
        $this->makeEligibleSenior('Aurelia', 'Batchmatch');
        $this->makeEligibleSenior('Benigno', 'Batchother');

        $this->actingAs($this->admin)
            ->get(route('ml.batch', ['search' => 'Aurelia']))
            ->assertOk()
            ->assertSee('Aurelia Batchmatch')
            ->assertDontSee('Benigno Batchother');
    }

    #[Test]
    public function registry_landing_page_renders_for_admin_with_download_link(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.registry'))
            ->assertOk()
            ->assertSee('Senior Registry')
            ->assertSee(route('reports.registry.export'), false);
    }

    #[Test]
    public function registry_landing_page_is_forbidden_for_viewer(): void
    {
        $this->actingAs($this->viewer)
            ->get(route('reports.registry'))
            ->assertForbidden();
    }
}
