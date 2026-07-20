<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\MainDashboard;
use App\Models\MlResult;
use App\Models\QolSurvey;
use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use App\Models\User;
use App\Support\SeniorDataVersion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DashboardPerformanceTest extends TestCase
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
    public function senior_citizens_table_has_status_barangay_composite_index(): void
    {
        $indexNames = collect(Schema::getIndexes('senior_citizens'))->pluck('name');

        $this->assertContains('senior_citizens_status_barangay_idx', $indexNames);
    }

    #[Test]
    public function recent_seniors_and_pending_recommendations_are_cached(): void
    {
        $senior = SeniorCitizen::create([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
        ]);

        $survey = QolSurvey::create([
            'senior_citizen_id' => $senior->id,
            'survey_date' => now()->format('Y-m-d'),
            'status' => 'processed',
        ]);

        $ml = MlResult::create([
            'senior_citizen_id' => $senior->id,
            'qol_survey_id' => $survey->id,
            'model_version' => '2.0.0',
            'prediction_source' => 'live_model',
            'overall_risk_level' => 'HIGH',
            'ic_risk' => 0.6, 'env_risk' => 0.5, 'func_risk' => 0.7,
            'wellbeing_score' => 0.41,
            'cluster_named_id' => 4,
            'scored_at' => now(),
            'processed_at' => now(),
        ]);

        Recommendation::create([
            'ml_result_id' => $ml->id,
            'senior_citizen_id' => $senior->id,
            'priority' => 1,
            'category' => 'healthcare_access',
            'urgency' => 'urgent',
            'risk_level' => 'HIGH',
            'action' => 'Test urgent action',
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(MainDashboard::class);

        // Reconstruct MainDashboard::cacheKey('recent_seniors' / 'pending_recs')
        // with no filters selected (empty barangay/risk) to confirm the render
        // above actually populated these cache entries.
        $version = SeniorDataVersion::current();
        $suffix = md5('|');

        $this->assertTrue(Cache::has("dashboard.recent_seniors.{$version}.{$suffix}"));
        $this->assertTrue(Cache::has("dashboard.pending_recs.{$version}.{$suffix}"));
    }
}
