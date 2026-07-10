<?php

namespace Tests\Feature;

use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Task 8 — ML API authentication (Flask). Verifies MlService sends the
 * X-Internal-Api-Key header (config('services.python.token'), backed by
 * ML_SERVICE_TOKEN) on every data-bearing call to the preprocess/inference
 * Flask services, and that /health polling calls are never given the header
 * — those are used by startServices()/stopServices()/healthCheck()/
 * checkHealth() before/without auth context and must stay reachable
 * regardless of token configuration.
 */
class MlServiceAuthTest extends TestCase
{
    use DatabaseTransactions;

    private const TEST_TOKEN = 'test-internal-token-123';

    private SeniorCitizen $senior;

    private QolSurvey $survey;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.python.token' => self::TEST_TOKEN]);

        $this->senior = SeniorCitizen::create([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => 'Test',
            'last_name' => 'Senior',
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
        ]);

        $this->survey = QolSurvey::create([
            'senior_citizen_id' => $this->senior->id,
            'survey_version' => 'v1',
            'survey_date' => '2026-01-01',
            'status' => 'processed',
        ]);
    }

    private function fakeInferResponse(): array
    {
        return [
            'cluster' => ['raw_id' => 0, 'named_id' => 1, 'name' => 'Test Cluster'],
            'risk_scores' => ['composite_risk' => 0.2],
            'risk_levels' => ['overall' => 'LOW'],
            'model_metadata' => ['prediction_source' => 'live_model'],
        ];
    }

    /** Requests captured for the given URL substring must all carry the expected auth header. */
    private function assertHeaderSentTo(string $urlContains): void
    {
        Http::assertSent(function (HttpClientRequest $request) use ($urlContains) {
            if (! str_contains($request->url(), $urlContains)) {
                return false;
            }

            $this->assertSame(
                self::TEST_TOKEN,
                $request->header('X-Internal-Api-Key')[0] ?? null,
                "Expected X-Internal-Api-Key header on request to {$urlContains}"
            );

            return true;
        });
    }

    /** Requests captured for the given URL substring must NEVER carry the auth header. */
    private function assertNoHeaderSentTo(string $urlContains): void
    {
        Http::assertSent(function (HttpClientRequest $request) use ($urlContains) {
            if (! str_contains($request->url(), $urlContains)) {
                return false;
            }

            $this->assertNull(
                $request->header('X-Internal-Api-Key')[0] ?? null,
                "Did not expect X-Internal-Api-Key header on request to {$urlContains}"
            );

            return true;
        });
    }

    #[Test]
    public function preprocess_and_infer_calls_carry_the_auth_header_while_health_does_not(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'ok'], 200),
            '*/preprocess' => Http::response(['section_scores' => []], 200),
            '*/infer' => Http::response($this->fakeInferResponse(), 200),
        ]);

        $service = app(MlService::class);
        $result = $service->runPipeline($this->senior, $this->survey, force: true);

        $this->assertNotNull($result);

        $this->assertHeaderSentTo('/preprocess');
        $this->assertHeaderSentTo('/infer');
        $this->assertNoHeaderSentTo('/health');
    }

    #[Test]
    public function batch_preprocess_and_batch_infer_calls_carry_the_auth_header(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'ok'], 200),
            '*/batch_preprocess' => Http::response([
                'results' => [['section_scores' => []]],
            ], 200),
            '*/batch_infer' => Http::response([
                'results' => [$this->fakeInferResponse()],
            ], 200),
        ]);

        $service = app(MlService::class);
        $results = $service->runBatchPipeline([
            ['senior' => $this->senior, 'survey' => $this->survey],
        ]);

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['success']);

        $this->assertHeaderSentTo('/batch_preprocess');
        $this->assertHeaderSentTo('/batch_infer');
        $this->assertNoHeaderSentTo('/health');
    }

    #[Test]
    public function preprocess_retry_after_cold_start_timeout_still_carries_the_auth_header(): void
    {
        $calls = 0;

        Http::fake([
            '*/health' => Http::response(['status' => 'ok'], 200),
            '*/preprocess' => function () use (&$calls) {
                $calls++;
                if ($calls === 1) {
                    // First attempt "times out" — ConnectionException with a
                    // message containing "timed out" triggers callPreprocess()'s
                    // cold-start retry path (longer timeout, same headers).
                    throw new ConnectionException('Connection timed out after 5000ms');
                }

                return Http::response(['section_scores' => []], 200);
            },
            '*/infer' => Http::response($this->fakeInferResponse(), 200),
        ]);

        $service = app(MlService::class);
        $result = $service->runPipeline($this->senior, $this->survey, force: true);

        $this->assertNotNull($result);
        $this->assertSame(2, $calls, 'Expected the preprocess call to be retried once after a simulated timeout.');

        $this->assertHeaderSentTo('/preprocess');
        $this->assertNoHeaderSentTo('/health');
    }

    #[Test]
    public function empty_token_configuration_sends_no_auth_header(): void
    {
        config(['services.python.token' => null]);

        Http::fake([
            '*/health' => Http::response(['status' => 'ok'], 200),
            '*/preprocess' => Http::response(['section_scores' => []], 200),
            '*/infer' => Http::response($this->fakeInferResponse(), 200),
        ]);

        $service = app(MlService::class);
        $result = $service->runPipeline($this->senior, $this->survey, force: true);

        $this->assertNotNull($result);

        $this->assertNoHeaderSentTo('/preprocess');
        $this->assertNoHeaderSentTo('/infer');
        $this->assertNoHeaderSentTo('/health');
    }
}
