<?php

namespace Tests\Feature;

use App\Livewire\Reports\RiskReport;
use App\Models\MlResult;
use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Batch5ReportsTest extends TestCase
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

    private function makeSeniorWithRisk(string $level, string $first, string $last, string $barangay = 'Anibong'): SeniorCitizen
    {
        $senior = SeniorCitizen::create([
            'osca_id' => SeniorCitizen::generateOscaId($barangay),
            'first_name' => $first,
            'last_name' => $last,
            'barangay' => $barangay,
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

        MlResult::create([
            'senior_citizen_id' => $senior->id,
            'qol_survey_id' => $survey->id,
            'model_version' => '2.0.0',
            'prediction_source' => 'live_model',
            'overall_risk_level' => $level,
            'ic_risk' => 0.5, 'env_risk' => 0.5, 'func_risk' => 0.5,
            'composite_risk' => 0.5, 'wellbeing_score' => 0.5,
            'cluster_named_id' => 2,
            'scored_at' => now(), 'processed_at' => now(),
        ]);

        return $senior;
    }

    #[Test]
    public function risk_report_search_matches_name(): void
    {
        $this->makeSeniorWithRisk('HIGH', 'Aurelio', 'Searchtarget');
        $this->makeSeniorWithRisk('HIGH', 'Benigno', 'Otherperson');

        $this->actingAs($this->admin);

        Livewire::test(RiskReport::class)
            ->set('filterSearch', 'Aurelio')
            ->assertSee('Aurelio Searchtarget')
            ->assertDontSee('Benigno Otherperson');
    }

    #[Test]
    public function risk_report_sort_column_rejects_unlisted_columns(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(RiskReport::class)
            ->call('sortColumn', 'ic_risk')
            ->assertSet('sortBy', 'ic_risk')
            ->call('sortColumn', 'first_name; DROP TABLE')
            ->assertSet('sortBy', 'ic_risk');
    }

    #[Test]
    public function risk_export_includes_all_levels_by_default_and_respects_risk_filter(): void
    {
        $this->makeSeniorWithRisk('HIGH', 'Zelda', 'Highexport');
        $this->makeSeniorWithRisk('LOW', 'Yanni', 'Lowexport');

        // Default export — both levels present.
        $all = $this->actingAs($this->admin)
            ->get(route('reports.risk.export'))
            ->streamedContent();
        $this->assertStringContainsString('Zelda Highexport', $all);
        $this->assertStringContainsString('Yanni Lowexport', $all);

        // Filtered to LOW — only the low-risk senior.
        $lowOnly = $this->actingAs($this->admin)
            ->get(route('reports.risk.export', ['risk' => 'low']))
            ->streamedContent();
        $this->assertStringContainsString('Yanni Lowexport', $lowOnly);
        $this->assertStringNotContainsString('Zelda Highexport', $lowOnly);
    }
}
