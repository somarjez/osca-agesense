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

/**
 * Regression coverage for TC-PERF-05 (audit finding): /recommendations took
 * 8.7-9.5s because RecommendationController::index() sorted by two
 * withCount()-derived correlated-subquery columns BEFORE paginating. Rewrote
 * to a single GROUP BY derived table joined once (see index()'s comment) —
 * this file proves the rewrite preserved every filter/sort/quick-filter the
 * original withCount()-based query supported (search/name coverage already
 * lives in Batch2RecordsRecommendationsTest).
 */
class RecommendationIndexQueryTest extends TestCase
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
            ['email' => 'recqidx-admin@osca.local'],
            ['name' => 'RecQIdx Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);
        $this->actingAs($this->admin);
    }

    private function makeSeniorWithRecs(array $seniorOverrides, string $riskLevel, array $recSpecs): SeniorCitizen
    {
        $senior = SeniorCitizen::create(array_merge([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => 'RecQIdx',
            'last_name' => 'Test'.uniqid(),
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1, 'num_children' => 0, 'num_working_children' => 0,
        ], $seniorOverrides));

        $survey = QolSurvey::create(['senior_citizen_id' => $senior->id, 'survey_date' => now(), 'status' => 'processed']);
        $ml = MlResult::create([
            'senior_citizen_id' => $senior->id, 'qol_survey_id' => $survey->id,
            'model_version' => '2.0.0', 'prediction_source' => 'live_model',
            'overall_risk_level' => $riskLevel, 'ic_risk' => 0.5, 'env_risk' => 0.5, 'func_risk' => 0.5,
            'wellbeing_score' => 0.5, 'cluster_named_id' => 1, 'cluster_name' => 'Test Cluster',
            'scored_at' => now(), 'processed_at' => now(),
        ]);

        foreach ($recSpecs as $spec) {
            Recommendation::create(array_merge([
                'ml_result_id' => $ml->id, 'senior_citizen_id' => $senior->id,
                'priority' => 1, 'type' => 'general', 'domain' => 'medical',
                'action' => 'RecQIdx test action', 'urgency' => 'planned', 'status' => 'pending',
            ], $spec));
        }

        return $senior;
    }

    #[Test]
    public function seniors_with_more_immediate_recs_sort_first(): void
    {
        $high = $this->makeSeniorWithRecs(['first_name' => 'HighUrgencyQIdx'], 'HIGH', [
            ['urgency' => 'urgent', 'status' => 'pending'],
            ['urgency' => 'immediate', 'status' => 'pending'],
        ]);
        $low = $this->makeSeniorWithRecs(['first_name' => 'LowUrgencyQIdx'], 'LOW', [
            ['urgency' => 'planned', 'status' => 'pending'],
        ]);

        $response = $this->get(route('recommendations.index', ['barangay' => 'Anibong']));
        $response->assertOk();

        $ids = $response->viewData('seniors')->pluck('id')->values()->toArray();
        $highPos = array_search($high->id, $ids, true);
        $lowPos = array_search($low->id, $ids, true);

        $this->assertNotFalse($highPos);
        $this->assertNotFalse($lowPos);
        $this->assertLessThan($lowPos, $highPos, 'Senior with immediate/urgent recs must sort before one with only planned recs.');
    }

    #[Test]
    public function has_urgent_filter_excludes_seniors_with_no_immediate_recs(): void
    {
        $urgent = $this->makeSeniorWithRecs(['first_name' => 'UrgentOnlyQIdx'], 'HIGH', [
            ['urgency' => 'urgent', 'status' => 'pending'],
        ]);
        $planned = $this->makeSeniorWithRecs(['first_name' => 'PlannedOnlyQIdx'], 'LOW', [
            ['urgency' => 'planned', 'status' => 'pending'],
        ]);

        $response = $this->get(route('recommendations.index', ['has_urgent' => 1, 'barangay' => 'Anibong']));
        $response->assertOk();

        $ids = $response->viewData('seniors')->pluck('id')->values()->toArray();
        $this->assertContains($urgent->id, $ids);
        $this->assertNotContains($planned->id, $ids);
    }

    #[Test]
    public function quick_pending_and_done_filters_partition_correctly(): void
    {
        $pending = $this->makeSeniorWithRecs(['first_name' => 'StillPendingQIdx'], 'HIGH', [
            ['urgency' => 'planned', 'status' => 'pending'],
        ]);
        $done = $this->makeSeniorWithRecs(['first_name' => 'AllDoneQIdx'], 'LOW', [
            ['urgency' => 'planned', 'status' => 'completed'],
        ]);

        $pendingIds = $this->get(route('recommendations.index', ['quick' => 'pending', 'barangay' => 'Anibong']))
            ->viewData('seniors')->pluck('id')->values()->toArray();
        $this->assertContains($pending->id, $pendingIds);
        $this->assertNotContains($done->id, $pendingIds);

        $doneIds = $this->get(route('recommendations.index', ['quick' => 'done', 'barangay' => 'Anibong']))
            ->viewData('seniors')->pluck('id')->values()->toArray();
        $this->assertContains($done->id, $doneIds);
        $this->assertNotContains($pending->id, $doneIds);
    }

    #[Test]
    public function risk_filter_narrows_to_the_selected_level(): void
    {
        $high = $this->makeSeniorWithRecs(['first_name' => 'RiskHighQIdx'], 'HIGH', [
            ['urgency' => 'planned', 'status' => 'pending'],
        ]);
        $low = $this->makeSeniorWithRecs(['first_name' => 'RiskLowQIdx'], 'LOW', [
            ['urgency' => 'planned', 'status' => 'pending'],
        ]);

        $ids = $this->get(route('recommendations.index', ['risk' => 'high', 'barangay' => 'Anibong']))
            ->viewData('seniors')->pluck('id')->values()->toArray();

        $this->assertContains($high->id, $ids);
        $this->assertNotContains($low->id, $ids);
    }

    #[Test]
    public function counts_reflect_only_current_ml_results_recommendations(): void
    {
        $senior = $this->makeSeniorWithRecs(['first_name' => 'CountCheckQIdx'], 'HIGH', [
            ['urgency' => 'immediate', 'status' => 'pending'],
            ['urgency' => 'planned', 'status' => 'pending'],
            ['urgency' => 'planned', 'status' => 'completed'],
        ]);

        $row = $this->get(route('recommendations.index', ['barangay' => 'Anibong']))
            ->viewData('seniors')->firstWhere('id', $senior->id);

        $this->assertNotNull($row);
        $this->assertSame(3, (int) $row->recommendations_count);
        $this->assertSame(2, (int) $row->pending_count);
        $this->assertSame(1, (int) $row->immediate_count);
    }

    /**
     * Regression for the "20 on the profile, 40 on this page" duplication
     * bug: a re-run soft-deletes the old recommendations on the same
     * ml_result (MlService::persistResults()) and inserts a fresh set. The
     * raw DB::table() aggregates here must exclude the soft-deleted rows —
     * see CurrentMlResult and index()'s whereNull('recommendations.deleted_at').
     */
    #[Test]
    public function re_run_does_not_double_count_superseded_recommendations(): void
    {
        $senior = $this->makeSeniorWithRecs(['first_name' => 'RerunDupQIdx'], 'HIGH', [
            ['urgency' => 'immediate', 'status' => 'pending'],
            ['urgency' => 'planned', 'status' => 'pending'],
        ]);

        // Simulate a re-run against the SAME ml_result: soft-delete the old
        // recommendations, then insert a fresh set — exactly what
        // MlService::persistResults() does.
        $mlResultId = $senior->recommendations()->first()->ml_result_id;
        Recommendation::where('ml_result_id', $mlResultId)->delete();
        for ($i = 0; $i < 2; $i++) {
            Recommendation::create([
                'ml_result_id' => $mlResultId, 'senior_citizen_id' => $senior->id,
                'priority' => 1, 'type' => 'general', 'domain' => 'medical',
                'action' => 'RecQIdx rerun action', 'urgency' => 'planned', 'status' => 'pending',
            ]);
        }

        $this->assertSame(
            2,
            Recommendation::where('ml_result_id', $mlResultId)->count(),
            'Sanity check: soft-deleted originals must not appear in a plain Eloquent count.'
        );

        $row = $this->get(route('recommendations.index', ['barangay' => 'Anibong']))
            ->viewData('seniors')->firstWhere('id', $senior->id);

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->recommendations_count, 'Superseded recommendations must not be double-counted after a re-run.');
    }

    /**
     * $stats['seniors'] was rebuilt to reuse the shared $recCounts derived
     * table instead of SeniorCitizen::active()->whereHas('currentRecommendations')
     * — must still exclude a deceased senior's current recs, exactly as the
     * original whereHas(active()) chain did. Runs against the real dev
     * database (DatabaseTransactions rolls back per-test but pre-existing
     * seeded rows remain visible), so this asserts on the DELTA a fresh
     * fixture causes, not an absolute count.
     */
    #[Test]
    public function seniors_stat_excludes_non_active_seniors(): void
    {
        $before = $this->get(route('recommendations.index'))->viewData('stats')['seniors'];

        $this->makeSeniorWithRecs(['first_name' => 'DeceasedStatQIdx', 'status' => 'deceased'], 'HIGH', [
            ['urgency' => 'planned', 'status' => 'pending'],
        ]);
        $afterDeceasedOnly = $this->get(route('recommendations.index'))->viewData('stats')['seniors'];
        $this->assertSame($before, $afterDeceasedOnly, 'A deceased senior with a current rec must not move the count.');

        $this->makeSeniorWithRecs(['first_name' => 'ActiveStatQIdx'], 'HIGH', [
            ['urgency' => 'planned', 'status' => 'pending'],
        ]);
        $afterBoth = $this->get(route('recommendations.index'))->viewData('stats')['seniors'];
        $this->assertSame($before + 1, $afterBoth, 'An active senior with a current rec must move the count by exactly 1.');
    }
}
