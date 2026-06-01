<?php

namespace Tests\Feature;

use App\Models\MlResult;
use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests that every export endpoint returns the correct content type,
 * a download disposition, and non-empty body for the seeded data set.
 *
 * DatabaseTransactions wraps each test in a transaction that is rolled
 * back afterwards, so the seeded 283-senior dataset is never touched.
 */
class ExportTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $encoder;

    private User $viewer;

    private SeniorCitizen $senior;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure roles exist — idempotent whether or not UserSeeder has run.
        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin@osca.local'],
            ['name' => 'OSCA Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);

        $this->encoder = User::firstOrCreate(
            ['email' => 'encoder@osca.local'],
            ['name' => 'OSCA Encoder', 'password' => Hash::make('password')]
        );
        $this->encoder->syncRoles(['encoder']);

        $this->viewer = User::firstOrCreate(
            ['email' => 'viewer@osca.local'],
            ['name' => 'OSCA Viewer', 'password' => Hash::make('password')]
        );
        $this->viewer->syncRoles(['viewer']);

        // Seed a minimal active senior + ML result so all export tests have data.
        // DatabaseTransactions rolls everything back after each test.
        $this->senior = SeniorCitizen::firstOrCreate(
            ['osca_id' => 'TST-2026-0001'],
            [
                'first_name' => 'Test',
                'last_name' => 'Senior',
                'barangay' => 'Barangay I',
                'date_of_birth' => '1950-01-01',
                'age' => 76,
                'gender' => 'Male',
                'status' => 'active',
            ]
        );

        // HIGH risk so the senior appears in both the cluster CSV and risk CSV exports.
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

    // ── Senior PDF export ─────────────────────────────────────────────────

    #[Test]
    public function senior_pdf_export_returns_pdf_for_admin()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('seniors.export', $this->senior));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString(
            'attachment',
            $response->headers->get('Content-Disposition')
        );
        $this->assertStringContainsString(
            $this->senior->osca_id,
            $response->headers->get('Content-Disposition')
        );
        $this->assertNotEmpty($response->getContent());
    }

    #[Test]
    public function senior_pdf_export_returns_pdf_for_encoder()
    {
        $response = $this->actingAs($this->encoder)
            ->get(route('seniors.export', $this->senior));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    #[Test]
    public function senior_pdf_export_returns_pdf_for_viewer()
    {
        $response = $this->actingAs($this->viewer)
            ->get(route('seniors.export', $this->senior));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    #[Test]
    public function senior_pdf_export_requires_authentication()
    {
        $this->get(route('seniors.export', $this->senior))
            ->assertRedirect(route('login'));
    }

    // ── Cluster CSV export ────────────────────────────────────────────────

    #[Test]
    public function cluster_csv_export_returns_csv_with_correct_headers()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('reports.cluster.export'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('osca_cluster_report_', $response->headers->get('Content-Disposition'));

        $body = $response->streamedContent();
        $this->assertNotEmpty($body);

        // Header row must be present
        $this->assertStringContainsString('OSCA ID', $body);
        $this->assertStringContainsString('Cluster ID', $body);
        $this->assertStringContainsString('Risk Level', $body);
        $this->assertStringContainsString('Composite Risk', $body);

        // Must have data rows beyond the header
        $lines = array_values(array_filter(explode("\n", trim($body))));
        $this->assertGreaterThan(1, count($lines), 'CSV has no data rows — only the header was written.');

        // Age column must never be 0 for a senior born in 1950.
        // Column order in cluster CSV: OSCA ID, Name, Barangay, Age, Gender, …
        $dataRow = str_getcsv($lines[1]);   // first data row (index 0 = header)
        $ageValue = (int) $dataRow[3];      // Age is the 4th column (0-indexed: 3)
        $this->assertGreaterThan(0, $ageValue,
            'Age column in cluster CSV is 0 — getAgeAttribute accessor bug.');
    }

    #[Test]
    public function cluster_csv_export_is_forbidden_for_encoder()
    {
        $this->actingAs($this->encoder)
            ->get(route('reports.cluster.export'))
            ->assertForbidden();
    }

    #[Test]
    public function cluster_csv_export_is_forbidden_for_viewer()
    {
        $this->actingAs($this->viewer)
            ->get(route('reports.cluster.export'))
            ->assertForbidden();
    }

    // ── Risk CSV export ───────────────────────────────────────────────────

    #[Test]
    public function risk_csv_export_returns_csv_with_correct_headers()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('reports.risk.export'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('osca_risk_report_', $response->headers->get('Content-Disposition'));

        $body = $response->streamedContent();
        $this->assertNotEmpty($body);

        $this->assertStringContainsString('OSCA ID', $body);
        $this->assertStringContainsString('Risk Level', $body);
        $this->assertStringContainsString('Composite Risk', $body);

        // Risk export only includes HIGH — verify all data rows say HIGH
        $lines = array_values(array_filter(explode("\n", trim($body))));
        foreach (array_slice($lines, 1) as $line) {
            // "HIGH" must appear in each data row
            $this->assertStringContainsString('HIGH', $line, "Data row does not contain HIGH: $line");
        }

        // Age must be non-zero in the risk CSV as well.
        // Column order in risk CSV: OSCA ID, Name, Barangay, Age, Risk Level, …
        $firstDataRow = str_getcsv($lines[1]);
        $ageValue = (int) $firstDataRow[3];
        $this->assertGreaterThan(0, $ageValue,
            'Age column in risk CSV is 0 — getAgeAttribute accessor bug.');
    }

    #[Test]
    public function risk_csv_export_is_forbidden_for_viewer()
    {
        $this->actingAs($this->viewer)
            ->get(route('reports.risk.export'))
            ->assertForbidden();
    }

    // ── Registry Excel export ─────────────────────────────────────────────

    #[Test]
    public function registry_excel_export_returns_xlsx()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('reports.registry.export'));

        $response->assertOk();

        // PhpSpreadsheet xlsx MIME
        $contentType = $response->headers->get('Content-Type');
        $this->assertStringContainsString(
            'spreadsheetml',
            $contentType,
            "Expected xlsx MIME (spreadsheetml), got: $contentType"
        );

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('osca_senior_registry_', $disposition);
        $this->assertStringContainsString('.xlsx', $disposition);

        // Excel::download() returns a BinaryFileResponse — content is in the streamed output.
        $content = method_exists($response->baseResponse, 'getFile')
            ? file_get_contents($response->baseResponse->getFile()->getPathname())
            : $response->streamedContent();

        $this->assertGreaterThan(1000, strlen($content),
            'XLSX file is suspiciously small — may be empty or broken.');
    }

    #[Test]
    public function registry_excel_export_is_forbidden_for_viewer()
    {
        $this->actingAs($this->viewer)
            ->get(route('reports.registry.export'))
            ->assertForbidden();
    }

    // ── Bulk upload sample template ───────────────────────────────────────

    #[Test]
    public function bulk_upload_sample_returns_csv_template()
    {
        $response = $this->actingAs($this->admin)
            ->get(route('seniors.bulk-upload.sample'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('bulk_upload_template.csv', $response->headers->get('Content-Disposition'));

        $body = $response->getContent();

        // Header row must contain all required columns
        $headerLine = explode("\n", $body)[0];
        foreach (['first_name', 'last_name', 'barangay', 'dob', 'gender'] as $col) {
            $this->assertStringContainsString($col, $headerLine,
                "Required column '$col' missing from template header.");
        }

        // Must have exactly 2 lines: header + one example row
        $lines = array_filter(explode("\n", trim($body)));
        $this->assertCount(2, $lines, 'Template should have header + 1 example row.');
    }

    #[Test]
    public function bulk_upload_sample_is_forbidden_for_viewer()
    {
        $this->actingAs($this->viewer)
            ->get(route('seniors.bulk-upload.sample'))
            ->assertForbidden();
    }
}
