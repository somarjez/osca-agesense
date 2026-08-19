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

class ArchiveCascadeTest extends TestCase
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

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeSenior(): SeniorCitizen
    {
        return SeniorCitizen::create([
            'osca_id' => 'TST-'.uniqid(),
            'first_name' => 'Cascade',
            'last_name' => 'TestSenior',
            'barangay' => 'Pagsanjan',
            'date_of_birth' => '1950-06-15',
            'gender' => 'Male',
            'status' => 'active',
            'encoded_by' => 'Test',
        ]);
    }

    private function makeResult(SeniorCitizen $senior): MlResult
    {
        return MlResult::create([
            'senior_citizen_id' => $senior->id,
            'model_version' => 'v1',
        ]);
    }

    private function makeRecommendation(MlResult $result, SeniorCitizen $senior): Recommendation
    {
        return Recommendation::create([
            'ml_result_id' => $result->id,
            'senior_citizen_id' => $senior->id,
            'priority' => 1,
            'type' => 'general',
            'action' => 'Test action',
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    #[Test]
    public function archive_soft_deletes_ml_results_and_recommendations(): void
    {
        $senior = $this->makeSenior();
        $result = $this->makeResult($senior);
        $rec = $this->makeRecommendation($result, $senior);

        $this->actingAs($this->admin)
            ->delete(route('seniors.destroy', $senior))
            ->assertRedirect(route('seniors.index'));

        // Senior and related data should be soft-deleted
        $this->assertSoftDeleted('senior_citizens', ['id' => $senior->id]);
        $this->assertSoftDeleted('ml_results', ['id' => $result->id]);
        $this->assertSoftDeleted('recommendations', ['id' => $rec->id]);
    }

    /**
     * restore() deliberately restores only the senior and their QoL
     * surveys — NOT recommendations or ml_results. See restore()'s own
     * docblock: a timestamp-window approach for "which trashed rows belong
     * to this archive" was tried and shipped, then broke in production the
     * first time a re-run happened shortly before an archive (both
     * soft-deletes landed in the window, so restore brought back TWO
     * superseded generations at once — e.g. 34 recommendations shown where
     * only 17 should have been). Restoring nothing beyond the survey
     * sidesteps that ambiguity entirely; the profile page already renders
     * an "unassessed" state gracefully, and re-running regenerates a clean
     * set.
     */
    #[Test]
    public function restore_brings_back_the_senior_and_surveys_but_not_ml_results_or_recommendations(): void
    {
        $senior = $this->makeSenior();
        $result = $this->makeResult($senior);
        $rec = $this->makeRecommendation($result, $senior);
        $survey = QolSurvey::create([
            'senior_citizen_id' => $senior->id,
            'survey_date' => now(),
            'status' => 'processed',
        ]);

        // Archive first
        $this->actingAs($this->admin)
            ->delete(route('seniors.destroy', $senior));

        // Restore
        $this->actingAs($this->admin)
            ->post(route('seniors.restore', $senior->id))
            ->assertRedirect(route('seniors.archives'));

        // Senior and their QoL survey come back...
        $this->assertDatabaseHas('senior_citizens', ['id' => $senior->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('qol_surveys', ['id' => $survey->id, 'deleted_at' => null]);

        // ...but the ml_result and recommendation stay superseded — the
        // senior shows as needing re-assessment, not a stale/duplicated one.
        $this->assertSoftDeleted('ml_results', ['id' => $result->id]);
        $this->assertSoftDeleted('recommendations', ['id' => $rec->id]);
    }

    #[Test]
    public function force_delete_permanently_removes_ml_results_and_recommendations(): void
    {
        $senior = $this->makeSenior();
        $result = $this->makeResult($senior);
        $rec = $this->makeRecommendation($result, $senior);

        // Archive first (force-delete only works on archived seniors via UI)
        $this->actingAs($this->admin)
            ->delete(route('seniors.destroy', $senior));

        // Force-delete
        $this->actingAs($this->admin)
            ->delete(route('seniors.force-delete', $senior->id))
            ->assertRedirect(route('seniors.archives'));

        $this->assertDatabaseMissing('senior_citizens', ['id' => $senior->id]);
        $this->assertDatabaseMissing('ml_results', ['id' => $result->id]);
        $this->assertDatabaseMissing('recommendations', ['id' => $rec->id]);
    }

    #[Test]
    public function archived_ml_results_are_hidden_from_default_queries(): void
    {
        $senior = $this->makeSenior();
        $result = $this->makeResult($senior);

        // Archive cascades soft-delete to ml_results
        $this->actingAs($this->admin)
            ->delete(route('seniors.destroy', $senior));

        // Default MlResult query (no withTrashed) should not find it
        $found = MlResult::where('id', $result->id)->first();
        $this->assertNull($found, 'Soft-deleted MlResult should be excluded from default queries.');
    }

    /**
     * Regression for the production bug that killed the earlier windowed
     * approach: a re-run happening shortly before an archive used to leave
     * TWO recommendation generations inside the restore window, so
     * un-archiving resurrected both at once (a senior showing 17
     * recommendations went to 34 after archive → restore). Explicitly
     * exercises that exact sequence — re-run (supersedes the first
     * generation), then archive, then restore, all in quick succession —
     * and asserts only ONE generation is ever live at a time, regardless of
     * how close together the soft-deletes happened.
     */
    #[Test]
    public function a_re_run_immediately_before_an_archive_does_not_get_resurrected_alongside_the_live_snapshot(): void
    {
        $senior = $this->makeSenior();

        // Generation 1 — superseded by a "re-run" moments before archiving.
        $firstResult = $this->makeResult($senior);
        $firstRec = $this->makeRecommendation($firstResult, $senior);
        $firstRec->delete();
        $firstResult->delete();

        // Generation 2 — the live snapshot at archive time.
        $secondResult = $this->makeResult($senior);
        $secondRec = $this->makeRecommendation($secondResult, $senior);

        // Archive and restore back-to-back, same as generation 1's
        // supersession above — nothing here is time-shifted, so this is the
        // worst case for any timestamp-window approach.
        $this->actingAs($this->admin)->delete(route('seniors.destroy', $senior));
        $this->actingAs($this->admin)
            ->post(route('seniors.restore', $senior->id))
            ->assertRedirect(route('seniors.archives'));

        // Neither generation is restored — both stay trashed, and a fresh
        // re-run (not a resurrection) is what produces the next live set.
        $this->assertSoftDeleted('ml_results', ['id' => $firstResult->id]);
        $this->assertSoftDeleted('recommendations', ['id' => $firstRec->id]);
        $this->assertSoftDeleted('ml_results', ['id' => $secondResult->id]);
        $this->assertSoftDeleted('recommendations', ['id' => $secondRec->id]);
    }
}
