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
 * Regression coverage for "Other #1": deleting a senior's latest QoL survey
 * left its ml_result pointing at a trashed survey (an "orphan"). Every
 * "latest ml_result" reader (SeniorCitizen::latestMlResult(),
 * Recommendation::scopeCurrent(), MlController::resultStatus()) still picked
 * that orphan, so re-running the assessment correctly recomputed the older,
 * surviving survey's ml_result but the profile kept showing the orphan's
 * stale, unchanged data — "Re-run Assessment" appeared to do nothing.
 *
 * SurveyController::qolDestroy() now cascades (recommendations → ml_result →
 * survey), and App\Support\CurrentMlResult centralizes "latest" to exclude
 * any orphan that slips through some other path.
 */
class QolDeleteMlResultIntegrityTest extends TestCase
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
            ['email' => 'qol-cascade-admin@osca.local'],
            ['name' => 'QoL Cascade Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);
        $this->actingAs($this->admin);
    }

    private function makeSenior(): SeniorCitizen
    {
        return SeniorCitizen::create([
            'osca_id' => 'QCT-'.uniqid(),
            'first_name' => 'QolCascade',
            'last_name' => 'TestSenior',
            'barangay' => 'Pagsanjan',
            'date_of_birth' => '1950-06-15',
            'gender' => 'Male',
            'status' => 'active',
            'encoded_by' => 'Test',
        ]);
    }

    private function makeSurveyWithResult(SeniorCitizen $senior, int $recCount = 2): array
    {
        $survey = QolSurvey::create([
            'senior_citizen_id' => $senior->id,
            'survey_date' => now(),
            'status' => 'processed',
        ]);

        $result = MlResult::create([
            'senior_citizen_id' => $senior->id,
            'qol_survey_id' => $survey->id,
            'model_version' => 'v1',
            'processed_at' => now(),
        ]);

        for ($i = 0; $i < $recCount; $i++) {
            Recommendation::create([
                'ml_result_id' => $result->id, 'senior_citizen_id' => $senior->id,
                'priority' => 1, 'type' => 'general', 'action' => 'Test action',
            ]);
        }

        return [$survey, $result];
    }

    #[Test]
    public function deleting_the_latest_survey_cascades_its_ml_result_and_recommendations(): void
    {
        $senior = $this->makeSenior();
        [$oldSurvey, $oldResult] = $this->makeSurveyWithResult($senior);
        [$newSurvey, $newResult] = $this->makeSurveyWithResult($senior);

        $this->actingAs($this->admin)
            ->delete(route('surveys.qol.destroy', $newSurvey))
            ->assertRedirect();

        $this->assertSoftDeleted('qol_surveys', ['id' => $newSurvey->id]);
        $this->assertSoftDeleted('ml_results', ['id' => $newResult->id]);
        $this->assertSoftDeleted('recommendations', ['ml_result_id' => $newResult->id]);

        // The older survey's data is untouched.
        $this->assertDatabaseHas('qol_surveys', ['id' => $oldSurvey->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('ml_results', ['id' => $oldResult->id, 'deleted_at' => null]);
    }

    #[Test]
    public function latest_ml_result_resolves_to_the_surviving_survey_after_the_newer_one_is_deleted(): void
    {
        $senior = $this->makeSenior();
        [, $oldResult] = $this->makeSurveyWithResult($senior);
        [$newSurvey, $newResult] = $this->makeSurveyWithResult($senior);

        // Before deletion, "latest" is the newer result.
        $this->assertSame($newResult->id, $senior->fresh()->latestMlResult?->id);

        $this->actingAs($this->admin)->delete(route('surveys.qol.destroy', $newSurvey));

        // After deletion, "latest" must resolve to the surviving older
        // result — not the now-trashed orphan, and not null.
        $latest = $senior->fresh()->latestMlResult;
        $this->assertNotNull($latest, '"Latest" must fall back to the surviving ml_result, not disappear.');
        $this->assertSame($oldResult->id, $latest->id);
    }

    #[Test]
    public function current_recommendations_follow_the_surviving_result_after_the_newer_survey_is_deleted(): void
    {
        $senior = $this->makeSenior();
        [, $oldResult] = $this->makeSurveyWithResult($senior, recCount: 2);
        [$newSurvey] = $this->makeSurveyWithResult($senior, recCount: 3);

        $this->actingAs($this->admin)->delete(route('surveys.qol.destroy', $newSurvey));

        $current = $senior->fresh()->currentRecommendations()->get();
        $this->assertCount(2, $current);
        $this->assertTrue($current->every(fn ($r) => $r->ml_result_id === $oldResult->id));
    }
}
