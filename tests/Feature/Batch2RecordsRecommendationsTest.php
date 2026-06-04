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

class Batch2RecordsRecommendationsTest extends TestCase
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

    /** Create an ML result (and its backing survey) for a senior. Returns the MlResult. */
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
    public function ml_analysis_strip_uses_who_domain_labels_and_risk_caption(): void
    {
        $senior = $this->makeSenior();
        $this->makeMlResult($senior);

        $this->actingAs($this->admin)
            ->get(route('seniors.show', $senior))
            ->assertOk()
            ->assertSee('Intrinsic Capacity')
            ->assertSee('Functional Ability')
            ->assertSee('Overall risk');
    }

    /** Attach a recommendation to a given ML result. */
    private function makeRec(MlResult $ml, array $overrides = []): Recommendation
    {
        return Recommendation::create(array_merge([
            'ml_result_id' => $ml->id,
            'senior_citizen_id' => $ml->senior_citizen_id,
            'priority' => 1,
            'type' => 'general',
            'domain' => 'medical',
            'action' => 'Default action',
            'urgency' => 'planned',
            'status' => 'pending',
        ], $overrides));
    }

    #[Test]
    public function current_recommendations_returns_only_latest_ml_results_recs(): void
    {
        $senior = $this->makeSenior();

        $oldMl = $this->makeMlResult($senior);
        $this->makeRec($oldMl, ['action' => 'OLD recommendation']);

        $newMl = $this->makeMlResult($senior);
        $this->makeRec($newMl, ['action' => 'NEW recommendation A']);
        $this->makeRec($newMl, ['action' => 'NEW recommendation B']);

        $current = $senior->currentRecommendations()->get();

        $this->assertCount(2, $current);
        $this->assertEqualsCanonicalizing(
            ['NEW recommendation A', 'NEW recommendation B'],
            $current->pluck('action')->all()
        );
    }

    #[Test]
    public function recommendations_show_page_displays_only_current_recs(): void
    {
        $senior = $this->makeSenior();
        $oldMl = $this->makeMlResult($senior);
        $this->makeRec($oldMl, ['action' => 'STALE old action']);
        $newMl = $this->makeMlResult($senior);
        $this->makeRec($newMl, ['action' => 'FRESH current action']);

        $this->actingAs($this->admin)
            ->get(route('recommendations.show', $senior))
            ->assertOk()
            ->assertSee('FRESH current action')
            ->assertDontSee('STALE old action');
    }

    #[Test]
    public function recommendations_index_stats_count_only_current_recs(): void
    {
        $senior = $this->makeSenior();
        $oldMl = $this->makeMlResult($senior);
        $this->makeRec($oldMl, ['action' => 'old1']);
        $this->makeRec($oldMl, ['action' => 'old2']);
        $newMl = $this->makeMlResult($senior);
        $this->makeRec($newMl, ['action' => 'new1']);

        $this->actingAs($this->admin)
            ->get(route('recommendations.index'))
            ->assertOk();

        // This senior contributes exactly 1 current rec, not 3.
        $this->assertSame(1, Recommendation::current()
            ->where('senior_citizen_id', $senior->id)->count());
        // Verify the controller's withCount alias returns 1 for this senior
        // (direct query mirrors the controller's withCount logic).
        $row = SeniorCitizen::active()
            ->whereKey($senior->id)
            ->withCount(['currentRecommendations as recommendations_count'])
            ->first();
        $this->assertNotNull($row, 'Senior should be active and have current recs');
        $this->assertSame(1, (int) $row->recommendations_count);
    }

    #[Test]
    public function recommendations_index_search_matches_name_and_osca_id(): void
    {
        $alice = $this->makeSenior(['first_name' => 'Alicia', 'last_name' => 'Reyes']);
        $bob = $this->makeSenior(['first_name' => 'Roberto', 'last_name' => 'Tan']);
        foreach ([$alice, $bob] as $s) {
            $this->makeRec($this->makeMlResult($s));
        }

        $this->actingAs($this->admin)
            ->get(route('recommendations.index', ['search' => 'Alicia']))
            ->assertOk()
            ->assertSee('Alicia Reyes')
            ->assertDontSee('Roberto Tan');

        $this->actingAs($this->admin)
            ->get(route('recommendations.index', ['search' => $bob->osca_id]))
            ->assertOk()
            ->assertSee('Roberto Tan')
            ->assertDontSee('Alicia Reyes');
    }

    #[Test]
    public function exported_pdf_template_is_formal_and_uses_corrected_labels(): void
    {
        $senior = $this->makeSenior();
        $this->makeMlResult($senior);

        $html = view('seniors.pdf', ['senior' => $senior->fresh()])->render();

        // Formal document elements
        $this->assertStringContainsString('Office', $html);            // letterhead org line
        $this->assertStringContainsString('Prepared by', $html);       // signature block
        $this->assertStringContainsString('Generated on', $html);      // footer timestamp
        // Corrected WHO labels
        $this->assertStringContainsString('Intrinsic Capacity', $html);
        $this->assertStringContainsString('Functional Ability', $html);
        // No leftover teal palette
        $this->assertStringNotContainsString('#0f766e', $html);
        $this->assertStringNotContainsString('#f0fdfa', $html);
        $this->assertStringNotContainsString('#134e4a', $html);
    }
}
