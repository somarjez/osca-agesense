<?php

namespace Tests\Feature;

use App\Models\MlResult;
use App\Models\QolSurvey;
use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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

    private function makeSurvey(SeniorCitizen $senior): QolSurvey
    {
        return QolSurvey::create([
            'senior_citizen_id' => $senior->id,
            'survey_date' => now(),
            'status' => 'processed',
        ]);
    }

    /** Reads archived_with_senior_at directly, bypassing the model's cast/scope. */
    private function marker(QolSurvey $survey)
    {
        return DB::table('qol_surveys')->where('id', $survey->id)->value('archived_with_senior_at');
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
        $survey = $this->makeSurvey($senior);

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

        // ...and the archive marker is cleared on the way back, so a later
        // archive/restore cycle doesn't inherit it.
        $this->assertNull($this->marker($survey));

        // ...but the ml_result and recommendation stay superseded — the
        // senior shows as needing re-assessment, not a stale/duplicated one.
        $this->assertSoftDeleted('ml_results', ['id' => $result->id]);
        $this->assertSoftDeleted('recommendations', ['id' => $rec->id]);
    }

    /**
     * The reported bug: a QoL survey an admin had individually deleted
     * (SurveyController::qolDestroy(), an independent decision made BEFORE
     * the archive) used to come back when the senior was later archived and
     * restored, because restore() restored every trashed survey for the
     * senior with no notion of which archive (if any) trashed it. Fixed by
     * stamping archived_with_senior_at on surveys the archive cascade itself
     * trashes and scoping restore() to that marker — see
     * SeniorCitizenController::archiveCascade()/restoreArchivedSurveys().
     */
    #[Test]
    public function restore_does_not_resurrect_a_survey_deleted_individually_before_the_archive(): void
    {
        $senior = $this->makeSenior();
        $kept = $this->makeSurvey($senior);
        $deletedFirst = $this->makeSurvey($senior);

        // Admin deletes one survey on its own, before the senior is archived.
        $this->actingAs($this->admin)
            ->delete(route('surveys.qol.destroy', $deletedFirst))
            ->assertRedirect();
        $this->assertSoftDeleted('qol_surveys', ['id' => $deletedFirst->id]);

        // Now the senior itself gets archived and restored.
        $this->actingAs($this->admin)->delete(route('seniors.destroy', $senior));
        $this->actingAs($this->admin)
            ->post(route('seniors.restore', $senior->id))
            ->assertRedirect(route('seniors.archives'));

        $this->assertDatabaseHas('senior_citizens', ['id' => $senior->id, 'deleted_at' => null]);
        // The survey that was live at archive time comes back...
        $this->assertDatabaseHas('qol_surveys', ['id' => $kept->id, 'deleted_at' => null]);
        // ...but the one deleted beforehand must stay archived.
        $this->assertSoftDeleted('qol_surveys', ['id' => $deletedFirst->id]);
    }

    #[Test]
    public function archive_stamps_only_the_surveys_it_trashes(): void
    {
        $senior = $this->makeSenior();
        $kept = $this->makeSurvey($senior);
        $deletedFirst = $this->makeSurvey($senior);

        $this->actingAs($this->admin)->delete(route('surveys.qol.destroy', $deletedFirst));
        $this->actingAs($this->admin)->delete(route('seniors.destroy', $senior));

        $this->assertNotNull($this->marker($kept));
        $this->assertNull($this->marker($deletedFirst));
    }

    /**
     * Guards against an implementation that stamps the marker but forgets to
     * clear it on restore: if a survey deleted BETWEEN two archive cycles
     * kept a marker from the first cycle, the second restore would
     * incorrectly resurrect it.
     */
    #[Test]
    public function a_survey_deleted_between_two_archive_cycles_is_not_resurrected_by_the_second_restore(): void
    {
        $senior = $this->makeSenior();
        $keptThroughout = $this->makeSurvey($senior);
        $deletedBetweenCycles = $this->makeSurvey($senior);

        // Cycle 1: archive, then restore — both surveys are live at this point.
        $this->actingAs($this->admin)->delete(route('seniors.destroy', $senior));
        $this->actingAs($this->admin)->post(route('seniors.restore', $senior->id));
        $this->assertNull($this->marker($keptThroughout));
        $this->assertNull($this->marker($deletedBetweenCycles));

        // Between cycles, the admin deletes one survey on its own.
        $this->actingAs($this->admin)->delete(route('surveys.qol.destroy', $deletedBetweenCycles));
        $this->assertSoftDeleted('qol_surveys', ['id' => $deletedBetweenCycles->id]);

        // Cycle 2: archive, then restore again.
        $this->actingAs($this->admin)->delete(route('seniors.destroy', $senior));
        $this->actingAs($this->admin)->post(route('seniors.restore', $senior->id));

        $this->assertDatabaseHas('qol_surveys', ['id' => $keptThroughout->id, 'deleted_at' => null]);
        $this->assertSoftDeleted('qol_surveys', ['id' => $deletedBetweenCycles->id]);
    }

    #[Test]
    public function bulk_restore_leaves_individually_deleted_surveys_archived(): void
    {
        $seniorA = $this->makeSenior();
        $keptA = $this->makeSurvey($seniorA);
        $deletedA = $this->makeSurvey($seniorA);

        $seniorB = $this->makeSenior();
        $keptB = $this->makeSurvey($seniorB);
        $deletedB = $this->makeSurvey($seniorB);

        $this->actingAs($this->admin)->delete(route('surveys.qol.destroy', $deletedA));
        $this->actingAs($this->admin)->delete(route('surveys.qol.destroy', $deletedB));

        $this->actingAs($this->admin)
            ->post(route('seniors.bulk-archive'), ['ids' => [$seniorA->id, $seniorB->id]])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('seniors.bulk-restore'), ['ids' => [$seniorA->id, $seniorB->id]])
            ->assertRedirect(route('seniors.archives'));

        $this->assertDatabaseHas('senior_citizens', ['id' => $seniorA->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('senior_citizens', ['id' => $seniorB->id, 'deleted_at' => null]);

        $this->assertDatabaseHas('qol_surveys', ['id' => $keptA->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('qol_surveys', ['id' => $keptB->id, 'deleted_at' => null]);
        $this->assertNull($this->marker($keptA));
        $this->assertNull($this->marker($keptB));

        $this->assertSoftDeleted('qol_surveys', ['id' => $deletedA->id]);
        $this->assertSoftDeleted('qol_surveys', ['id' => $deletedB->id]);
    }

    #[Test]
    public function archives_page_lists_only_individually_deleted_surveys(): void
    {
        $senior = $this->makeSenior();
        $keptAtArchive = $this->makeSurvey($senior);
        $deletedIndividually = $this->makeSurvey($senior);

        $this->actingAs($this->admin)->delete(route('surveys.qol.destroy', $deletedIndividually));
        $this->actingAs($this->admin)->delete(route('seniors.destroy', $senior));

        $response = $this->actingAs($this->admin)->get(route('seniors.archives'));
        $response->assertOk();

        $archivedSurveyIds = $response->viewData('archivedSurveys')->pluck('id');

        $this->assertTrue($archivedSurveyIds->contains($deletedIndividually->id));
        $this->assertFalse($archivedSurveyIds->contains($keptAtArchive->id));
    }

    /**
     * SurveyController::qolRestore() clears the marker defensively, so a
     * survey restored directly through that route can never carry a stale
     * marker into the senior's next archive/restore cycle (see its own
     * docblock).
     */
    #[Test]
    public function individually_restoring_a_survey_clears_the_archive_marker(): void
    {
        $senior = $this->makeSenior();
        $survey = $this->makeSurvey($senior);

        // Archive and restore once so the survey carries a marker, then gets
        // it cleared by the normal senior-restore path...
        $this->actingAs($this->admin)->delete(route('seniors.destroy', $senior));
        $this->actingAs($this->admin)->post(route('seniors.restore', $senior->id));

        // ...archive again so it's trashed-with-marker, then restore it
        // directly via the QoL-specific route instead of the senior route.
        $this->actingAs($this->admin)->delete(route('seniors.destroy', $senior));
        $this->assertNotNull($this->marker($survey));

        $this->actingAs($this->admin)
            ->post(route('surveys.qol.restore', $survey->id))
            ->assertRedirect(route('seniors.archives'));

        $this->assertDatabaseHas('qol_surveys', ['id' => $survey->id, 'deleted_at' => null]);
        $this->assertNull($this->marker($survey));
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
