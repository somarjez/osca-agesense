<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ModelValidation;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression coverage for the "held-out set (0 seniors)" bug: commit
 * 67f8ec0 (#219) renamed risk_level_validation_summary.json's confusion
 * matrix key to confusion_matrix_3class in the git-tracked public artifact
 * without updating ModelValidation::riskClassification()'s reader. On any
 * environment without the gitignored storage/app/ml_validation/ mirror
 * (fresh clone, CI, Docker), the whole classification card silently
 * rendered zeros instead of an error.
 *
 * This mutates the real (gitignored) mirror file at
 * storage/app/ml_validation/reports/risk_level_validation_summary.json to
 * simulate each artifact generation — always restored in tearDown(), even
 * on failure.
 */
class ModelValidationPageTest extends TestCase
{
    private User $admin;

    private User $viewer;

    private string $mirrorPath;

    private ?string $mirrorBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::firstOrCreate(
            ['email' => 'mvpage-admin@osca.local'],
            ['name' => 'MVPage Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);

        $this->viewer = User::firstOrCreate(
            ['email' => 'mvpage-viewer@osca.local'],
            ['name' => 'MVPage Viewer', 'password' => Hash::make('password')]
        );
        $this->viewer->syncRoles(['viewer']);

        $this->mirrorPath = storage_path('app/ml_validation/reports/risk_level_validation_summary.json');
        if (is_file($this->mirrorPath)) {
            $this->mirrorBackup = file_get_contents($this->mirrorPath);
        }
    }

    protected function tearDown(): void
    {
        if ($this->mirrorBackup !== null) {
            file_put_contents($this->mirrorPath, $this->mirrorBackup);
        } elseif (is_file($this->mirrorPath)) {
            unlink($this->mirrorPath);
        }

        parent::tearDown();
    }

    private function writeArtifact(array $data): void
    {
        @mkdir(dirname($this->mirrorPath), 0775, true);
        file_put_contents($this->mirrorPath, json_encode($data));
    }

    #[Test]
    public function viewer_and_encoder_are_forbidden(): void
    {
        $this->actingAs($this->viewer)->get(route('reports.validation'))->assertForbidden();
    }

    #[Test]
    public function admin_sees_populated_classification_evidence_with_the_current_schema_key(): void
    {
        // Public/current schema — the key the actual git-tracked artifact uses.
        $this->writeArtifact([
            'accuracy' => 0.8222,
            'weighted_f1' => 0.8259,
            'high_recall' => 1.0,
            'selected_threshold_set' => 'balanced',
            'confusion_matrix_3class' => [
                'Pred_LOW' => ['True_LOW' => 138, 'True_MODERATE' => 30, 'True_HIGH' => 0],
                'Pred_MODERATE' => ['True_LOW' => 13, 'True_MODERATE' => 139, 'True_HIGH' => 0],
                'Pred_HIGH' => ['True_LOW' => 0, 'True_MODERATE' => 21, 'True_HIGH' => 19],
            ],
        ]);

        $response = $this->actingAs($this->admin)->get(route('reports.validation'));

        $response->assertOk();
        // "0 seniors" would also match inside "360 seniors" — assert the
        // exact bugged phrase (with its parenthesis) is gone instead.
        $response->assertDontSee('(0 seniors)');
        $response->assertDontSee('held-out set (');
        $response->assertSee('360 assessed seniors');
    }

    #[Test]
    public function admin_sees_populated_classification_evidence_with_the_legacy_schema_key(): void
    {
        // Legacy 4-class schema — the local-mirror key that predates #219.
        $this->writeArtifact([
            'accuracy' => 0.8167,
            'weighted_f1' => 0.8234,
            'selected_threshold_set' => 'balanced',
            'confusion_matrix' => [
                'Pred_LOW' => ['True_LOW' => 138, 'True_MODERATE' => 30, 'True_HIGH' => 0, 'True_CRITICAL' => 0],
                'Pred_MODERATE' => ['True_LOW' => 13, 'True_MODERATE' => 139, 'True_HIGH' => 0, 'True_CRITICAL' => 0],
                'Pred_HIGH' => ['True_LOW' => 0, 'True_MODERATE' => 21, 'True_HIGH' => 17, 'True_CRITICAL' => 0],
                'Pred_CRITICAL' => ['True_LOW' => 0, 'True_MODERATE' => 0, 'True_HIGH' => 2, 'True_CRITICAL' => 0],
            ],
        ]);

        $response = $this->actingAs($this->admin)->get(route('reports.validation'));

        $response->assertOk();
        $response->assertDontSee('(0 seniors)');
    }

    #[Test]
    public function classification_card_shows_an_empty_state_instead_of_misleading_zeros_when_the_matrix_is_missing(): void
    {
        // No confusion_matrix key under any name — simulates a genuinely
        // stale/broken artifact, not just a renamed key.
        $this->writeArtifact([
            'accuracy' => 0.8222,
            'selected_threshold_set' => 'balanced',
        ]);

        $response = $this->actingAs($this->admin)->get(route('reports.validation'));

        $response->assertOk();
        $response->assertSee("Classification evidence isn't available yet", false);
        $response->assertDontSee('(0 seniors)');
    }

    #[Test]
    public function risk_classification_reader_prefers_3class_over_legacy_over_4class_raw(): void
    {
        $mv = app(ModelValidation::class);

        $this->writeArtifact([
            'selected_threshold_set' => 'balanced',
            'confusion_matrix_3class' => [
                'Pred_LOW' => ['True_LOW' => 5],
            ],
            'confusion_matrix' => [
                'Pred_LOW' => ['True_LOW' => 999],
            ],
        ]);

        $result = $mv->riskClassification();

        $this->assertTrue($result['available']);
        $this->assertSame(5, $result['per_class']['LOW']['support'], 'confusion_matrix_3class must win over the legacy confusion_matrix key.');
    }

    #[Test]
    public function selected_threshold_falls_back_to_balanced_not_moderate(): void
    {
        $mv = app(ModelValidation::class);

        $this->writeArtifact([
            'confusion_matrix_3class' => [
                'Pred_LOW' => ['True_LOW' => 5],
            ],
            // selected_threshold_set deliberately omitted.
        ]);

        $result = $mv->riskClassification();

        $this->assertSame('balanced', $result['selected_threshold']);
    }
}
