<?php

namespace Tests\Feature;

use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin   = User::where('email', 'admin@osca.local')->firstOrFail();
        $this->encoder = User::where('email', 'encoder@osca.local')->firstOrFail();
        $this->viewer  = User::where('email', 'viewer@osca.local')->firstOrFail();
    }

    // ── Senior PDF export ─────────────────────────────────────────────────

    #[Test]
    public function senior_pdf_export_returns_pdf_for_admin()
    {
        $senior = SeniorCitizen::active()->first();
        $this->assertNotNull($senior, 'No active seniors in DB — run migrate:fresh --seed first.');

        $response = $this->actingAs($this->admin)
            ->get(route('seniors.export', $senior));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString(
            'attachment',
            $response->headers->get('Content-Disposition')
        );
        $this->assertStringContainsString(
            $senior->osca_id,
            $response->headers->get('Content-Disposition')
        );
        $this->assertNotEmpty($response->getContent());
    }

    #[Test]
    public function senior_pdf_export_returns_pdf_for_encoder()
    {
        $senior = SeniorCitizen::active()->first();

        $response = $this->actingAs($this->encoder)
            ->get(route('seniors.export', $senior));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    #[Test]
    public function senior_pdf_export_returns_pdf_for_viewer()
    {
        $senior = SeniorCitizen::active()->first();

        $response = $this->actingAs($this->viewer)
            ->get(route('seniors.export', $senior));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    #[Test]
    public function senior_pdf_export_requires_authentication()
    {
        $senior = SeniorCitizen::active()->first();

        $this->get(route('seniors.export', $senior))
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
        $lines = array_filter(explode("\n", trim($body)));
        $this->assertGreaterThan(1, count($lines), 'CSV has no data rows — only the header was written.');
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
        foreach (['first_name','last_name','barangay','dob','gender'] as $col) {
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
