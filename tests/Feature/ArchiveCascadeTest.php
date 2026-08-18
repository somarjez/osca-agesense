<?php

namespace Tests\Feature;

use App\Models\MlResult;
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

    #[Test]
    public function restore_restores_ml_results_and_recommendations(): void
    {
        $senior = $this->makeSenior();
        $result = $this->makeResult($senior);
        $rec = $this->makeRecommendation($result, $senior);

        // Archive first
        $this->actingAs($this->admin)
            ->delete(route('seniors.destroy', $senior));

        // Restore
        $this->actingAs($this->admin)
            ->post(route('seniors.restore', $senior->id))
            ->assertRedirect(route('seniors.archives'));

        // All records should be back with deleted_at = null
        $this->assertDatabaseHas('senior_citizens', ['id' => $senior->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('ml_results', ['id' => $result->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('recommendations', ['id' => $rec->id,    'deleted_at' => null]);
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
     * Regression: restore() used to restore EVERY trashed recommendation and
     * ml_result for a senior, not just the ones trashed by this archive —
     * resurrecting a recommendation set that a re-run had already
     * legitimately superseded (soft-deleted) before the archive happened.
     */
    #[Test]
    public function restore_does_not_resurrect_recommendations_superseded_before_the_archive(): void
    {
        $senior = $this->makeSenior();

        // Simulate a superseded (re-run) recommendation set: trashed well
        // before the archive (backdated past the archive cascade's 5-second
        // matching window — see restore()'s docblock), not as part of it.
        $staleResult = $this->makeResult($senior);
        $staleRec = $this->makeRecommendation($staleResult, $senior);
        $staleRec->delete();
        $staleResult->delete();
        // update() no-ops on deleted_at — it's not $fillable — so force it.
        $staleRec->forceFill(['deleted_at' => now()->subHour()])->save();
        $staleResult->forceFill(['deleted_at' => now()->subHour()])->save();

        // Current, live data at archive time.
        $liveResult = $this->makeResult($senior);
        $liveRec = $this->makeRecommendation($liveResult, $senior);

        $this->actingAs($this->admin)->delete(route('seniors.destroy', $senior));
        $this->actingAs($this->admin)
            ->post(route('seniors.restore', $senior->id))
            ->assertRedirect(route('seniors.archives'));

        // The archive-time data comes back...
        $this->assertDatabaseHas('ml_results', ['id' => $liveResult->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('recommendations', ['id' => $liveRec->id, 'deleted_at' => null]);

        // ...but the pre-archive superseded data must stay trashed.
        $this->assertSoftDeleted('ml_results', ['id' => $staleResult->id]);
        $this->assertSoftDeleted('recommendations', ['id' => $staleRec->id]);
    }
}
