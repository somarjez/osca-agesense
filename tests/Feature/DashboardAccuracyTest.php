<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\MainDashboard;
use App\Models\MlResult;
use App\Models\QolSurvey;
use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for the audit's TC-DASH-01 / TC-DASH-02 findings:
 * MainDashboard's Domain Scores widget over-weighted seniors with multiple
 * processed surveys, and the Cluster Distribution / Priority Recommendations
 * widgets silently ignored the risk/barangay filter chips that every other
 * widget on the same screen correctly applied.
 */
class DashboardAccuracyTest extends TestCase
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
            ['email' => 'dashacc-admin@osca.local'],
            ['name' => 'DashAcc Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);
        $this->actingAs($this->admin);
    }

    private function makeSenior(array $overrides = []): SeniorCitizen
    {
        return SeniorCitizen::create(array_merge([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => 'DashAccTest',
            'last_name' => 'Senior',
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
        ], $overrides));
    }

    #[Test]
    public function domain_scores_count_a_senior_with_multiple_processed_surveys_only_once(): void
    {
        // One senior with THREE processed surveys at very different domain
        // scores — before the fix, all 3 rows were averaged in, over-weighting
        // this senior 3x relative to every other senior with just 1 survey.
        $senior = $this->makeSenior();

        QolSurvey::create([
            'senior_citizen_id' => $senior->id, 'survey_date' => now()->subDays(60),
            'status' => 'processed', 'score_qol' => 0.20,
        ]);
        QolSurvey::create([
            'senior_citizen_id' => $senior->id, 'survey_date' => now()->subDays(30),
            'status' => 'processed', 'score_qol' => 0.20,
        ]);
        $latest = QolSurvey::create([
            'senior_citizen_id' => $senior->id, 'survey_date' => now(),
            'status' => 'processed', 'score_qol' => 0.90,
        ]);

        Cache::flush();

        $chart = Livewire::test(MainDashboard::class)
            ->set('selectedBarangay', $senior->barangay)
            ->viewData('domainScoreChart');

        $qolIndex = array_search('QoL', $chart['labels']);
        // If all 3 rows were averaged in: (0.20+0.20+0.90)/3 = 0.4333 -> 43.3.
        // With only the latest row counted: 0.90 -> 90.0. Only one senior
        // matches this barangay filter, so the result must equal the latest
        // survey's own score exactly.
        $this->assertEqualsWithDelta(round($latest->score_qol * 100, 1), $chart['data'][$qolIndex], 0.5);
    }

    #[Test]
    public function cluster_distribution_widget_applies_the_risk_filter(): void
    {
        $high = $this->makeSenior(['osca_id' => SeniorCitizen::generateOscaId('Anibong'), 'first_name' => 'HighRiskDashAcc']);
        $survey1 = QolSurvey::create(['senior_citizen_id' => $high->id, 'survey_date' => now(), 'status' => 'processed']);
        MlResult::create([
            'senior_citizen_id' => $high->id, 'qol_survey_id' => $survey1->id,
            'model_version' => '2.0.0', 'prediction_source' => 'live_model',
            'overall_risk_level' => 'HIGH', 'ic_risk' => 0.6, 'env_risk' => 0.5, 'func_risk' => 0.7,
            'wellbeing_score' => 0.3, 'cluster_named_id' => 4, 'cluster_name' => 'Low Functioning / Multi-Domain Priority Seniors',
            'scored_at' => now(), 'processed_at' => now(),
        ]);

        $low = $this->makeSenior(['osca_id' => SeniorCitizen::generateOscaId('Anibong'), 'first_name' => 'LowRiskDashAcc']);
        $survey2 = QolSurvey::create(['senior_citizen_id' => $low->id, 'survey_date' => now(), 'status' => 'processed']);
        MlResult::create([
            'senior_citizen_id' => $low->id, 'qol_survey_id' => $survey2->id,
            'model_version' => '2.0.0', 'prediction_source' => 'live_model',
            'overall_risk_level' => 'LOW', 'ic_risk' => 0.1, 'env_risk' => 0.1, 'func_risk' => 0.1,
            'wellbeing_score' => 0.9, 'cluster_named_id' => 1, 'cluster_name' => 'High Functioning / Well-Supported Seniors',
            'scored_at' => now(), 'processed_at' => now(),
        ]);

        Cache::flush();

        // Filtering to "high" must exclude the LOW-risk senior's cluster from
        // the distribution entirely — before the fix, selectedRisk was
        // silently dropped and both clusters always appeared.
        $filtered = Livewire::test(MainDashboard::class)
            ->set('selectedBarangay', 'Anibong')
            ->set('selectedRisk', 'high')
            ->viewData('clusterDistribution');

        $this->assertContains(4, $filtered['ids']);
        $this->assertNotContains(1, $filtered['ids']);
    }

    #[Test]
    public function pending_recommendations_widget_applies_both_filter_chips(): void
    {
        $senior = $this->makeSenior(['osca_id' => SeniorCitizen::generateOscaId('Anibong'), 'first_name' => 'RecFilterDashAcc']);
        $survey = QolSurvey::create(['senior_citizen_id' => $senior->id, 'survey_date' => now(), 'status' => 'processed']);
        $ml = MlResult::create([
            'senior_citizen_id' => $senior->id, 'qol_survey_id' => $survey->id,
            'model_version' => '2.0.0', 'prediction_source' => 'live_model',
            'overall_risk_level' => 'HIGH', 'ic_risk' => 0.6, 'env_risk' => 0.5, 'func_risk' => 0.7,
            'wellbeing_score' => 0.3, 'cluster_named_id' => 4, 'cluster_name' => 'Low Functioning / Multi-Domain Priority Seniors',
            'scored_at' => now(), 'processed_at' => now(),
        ]);
        Recommendation::create([
            'ml_result_id' => $ml->id, 'senior_citizen_id' => $senior->id,
            'priority' => 1, 'category' => 'healthcare_access', 'urgency' => 'urgent',
            'risk_level' => 'high', 'action' => 'DashAcc test action', 'status' => 'pending',
        ]);

        Cache::flush();

        // A barangay that this recommendation's senior does NOT belong to —
        // before the fix, this widget ignored the filter and would still
        // show the recommendation.
        $filtered = Livewire::test(MainDashboard::class)
            ->set('selectedBarangay', 'Pinagsanjan')
            ->viewData('pendingRecs');

        $this->assertFalse(
            $filtered->contains('id', $ml->recommendations()->first()?->id ?? -1)
        );
    }
}
