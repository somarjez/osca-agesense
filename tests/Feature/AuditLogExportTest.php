<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\MlResult;
use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task 6 — audit logging expansion. Verifies that viewing/exporting a senior
 * profile, exporting a dataset-wide report, and triggering ML analysis each
 * write a corresponding activity_logs row.
 */
class AuditLogExportTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private SeniorCitizen $senior;

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
            ['name' => 'OSCA Admin', 'password' => bcrypt('password')]
        );
        $this->admin->syncRoles(['admin']);

        $this->senior = SeniorCitizen::firstOrCreate(
            ['osca_id' => 'AUD-2026-0001'],
            [
                'first_name' => 'Audit',
                'last_name' => 'Senior',
                'barangay' => 'Barangay I',
                'date_of_birth' => '1950-01-01',
                'age' => 76,
                'gender' => 'Male',
                'status' => 'active',
            ]
        );

        MlResult::firstOrCreate(
            ['senior_citizen_id' => $this->senior->id],
            [
                'cluster_id' => 2,
                'cluster_named_id' => 3,
                'cluster_name' => 'Low Functioning / Multi-domain Risk',
                'overall_risk_level' => 'HIGH',
                'ic_risk_level' => 'high',
                'env_risk_level' => 'high',
                'func_risk_level' => 'high',
                'composite_risk' => 0.75,
                'ic_risk' => 0.72,
                'env_risk' => 0.68,
                'func_risk' => 0.70,
                'wellbeing_score' => 0.30,
                'processed_at' => now(),
            ]
        );
    }

    #[Test]
    public function viewing_a_senior_profile_creates_a_viewed_log_entry(): void
    {
        $this->actingAs($this->admin)
            ->get(route('seniors.show', $this->senior))
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->admin->id,
            'action' => 'viewed',
            'subject_type' => SeniorCitizen::class,
            'subject_id' => $this->senior->id,
        ]);
    }

    #[Test]
    public function exporting_a_senior_pdf_creates_an_exported_log_entry(): void
    {
        $this->actingAs($this->admin)
            ->get(route('seniors.export', $this->senior))
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->admin->id,
            'action' => 'exported',
            'subject_type' => SeniorCitizen::class,
            'subject_id' => $this->senior->id,
        ]);
    }

    #[Test]
    public function exporting_cluster_report_creates_an_exported_log_entry(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.cluster.export'))
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->admin->id,
            'action' => 'exported',
            'subject_type' => User::class,
            'subject_id' => $this->admin->id,
            'description' => 'Cluster report CSV exported',
        ]);
    }

    #[Test]
    public function exporting_gis_report_creates_an_exported_log_entry(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.gis.export'))
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->admin->id,
            'action' => 'exported',
            'subject_type' => User::class,
            'subject_id' => $this->admin->id,
            'description' => 'GIS report CSV exported',
        ]);
    }

    #[Test]
    public function exporting_registry_creates_an_exported_log_entry(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.registry.export'))
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->admin->id,
            'action' => 'exported',
            'subject_type' => User::class,
            'subject_id' => $this->admin->id,
            'description' => 'Registry Excel exported',
        ]);
    }

    #[Test]
    public function exporting_risk_report_creates_an_exported_log_entry(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.risk.export'))
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->admin->id,
            'action' => 'exported',
            'subject_type' => User::class,
            'subject_id' => $this->admin->id,
            'description' => 'Risk report CSV exported',
        ]);
    }

    #[Test]
    public function running_single_ml_analysis_creates_an_ml_run_triggered_log_entry(): void
    {
        Bus::fake();

        $survey = QolSurvey::create([
            'senior_citizen_id' => $this->senior->id,
            'survey_date' => now()->format('Y-m-d'),
            'status' => 'processed',
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('ml.run.single', $this->senior))
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->admin->id,
            'action' => 'ml_run_triggered',
            'subject_type' => SeniorCitizen::class,
            'subject_id' => $this->senior->id,
        ]);
    }

    #[Test]
    public function running_batch_ml_analysis_creates_a_single_ml_batch_triggered_log_entry(): void
    {
        Bus::fake();

        // Self-contained eligible senior — batchRun() requires at least one
        // senior with a processed QoL survey. This used to lean on whatever
        // real data happened to be in the shared dev database the test suite
        // ran against (see .env.testing); now that tests run against their
        // own isolated database, that data no longer exists by default, so
        // the fixture has to come from the test itself.
        $eligible = SeniorCitizen::create([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => 'Batch',
            'last_name' => 'Eligible',
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
        ]);
        QolSurvey::create([
            'senior_citizen_id' => $eligible->id,
            'survey_version' => 'v1',
            'survey_date' => '2026-01-01',
            'status' => 'processed',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('ml.batch.run'));

        $response->assertOk();

        $this->assertEquals(
            1,
            ActivityLog::where('action', 'ml_batch_triggered')->where('user_id', $this->admin->id)->count(),
            'Batch ML trigger should write exactly one activity_logs row, not one per senior.'
        );
    }
}
