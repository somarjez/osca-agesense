<?php

namespace Tests\Feature;

use App\Models\MlResult;
use App\Models\QolSurvey;
use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DashboardUiTest extends TestCase
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

    private function makeSenior(array $overrides = []): SeniorCitizen
    {
        return SeniorCitizen::create(array_merge([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
        ], $overrides));
    }

    private function makeMlResult(SeniorCitizen $senior, array $overrides = []): MlResult
    {
        $survey = QolSurvey::create([
            'senior_citizen_id' => $senior->id,
            'survey_date' => now()->format('Y-m-d'),
            'status' => 'processed',
        ]);

        return MlResult::create(array_merge([
            'senior_citizen_id' => $senior->id,
            'qol_survey_id' => $survey->id,
            'model_version' => '2.0.0',
            'prediction_source' => 'live_model',
            'overall_risk_level' => 'HIGH',
            'ic_risk' => 0.6, 'env_risk' => 0.5, 'func_risk' => 0.7,
            'wellbeing_score' => 0.41,
            'cluster_named_id' => 4,
            'cluster_name' => 'Low Functioning / Multi-Domain Priority Seniors',
            'scored_at' => now(),
            'processed_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function dashboard_renders_with_wellbeing_bands_and_ranked_profile_groups(): void
    {
        $senior = $this->makeSenior();
        $this->makeMlResult($senior);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Risk Distribution')
            ->assertSee('ranked by size')
            ->assertSee('Needs attention (below 50)')
            ->assertSee('need monitoring or action');
    }

    #[Test]
    public function topbar_has_account_menu_and_sidebar_footer_is_gone(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('aria-label="Account menu"', false)
            ->assertSee('Sign out');

        // The old sidebar footer used 3.5px icons with aria-label="Sign out";
        // the dropdown renders a labeled menu item instead.
        $response->assertDontSee('aria-label="Sign out"', false);
    }

    #[Test]
    public function recommendation_categories_resolve_distinct_labels_on_profile(): void
    {
        $senior = $this->makeSenior();
        $ml = $this->makeMlResult($senior);

        foreach (['healthcare_access', 'livelihood', 'mental_health'] as $i => $category) {
            Recommendation::create([
                'ml_result_id' => $ml->id,
                'senior_citizen_id' => $senior->id,
                'priority' => $i + 1,
                'category' => $category,
                'urgency' => 'planned',
                'risk_level' => 'HIGH',
                'action' => 'Test action for '.$category,
                'status' => 'pending',
            ]);
        }

        $this->actingAs($this->admin)
            ->get(route('seniors.show', $senior))
            ->assertOk()
            ->assertSee('Healthcare Access')
            ->assertSee('Livelihood')
            ->assertSee('Mental Health');
    }
}
