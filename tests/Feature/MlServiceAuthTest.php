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
 * Flask services.
 *
 * runPipeline()/runBatchPipeline() no longer probe /health before attempting
 * real work (see MlService::postWithColdStartRetry — a fast /health check
 * can't safely decide "should we even try posting", since a sleeping Render
 * free-tier service answers 502/503/504 just as fast as it would answer
 * /health, and would be rejected outright before ever getting the chance to
 * wake up). /health is still used elsewhere (startServices()/stopServices()/
 * healthCheck()/checkHealth(), for the dashboard/status-page badges) and
 * still never receives the auth header there — just not exercised by these
 * pipeline-level tests.
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
        // Cold-start retries poll with a real usleep() between attempts
        // (see MlService::postWithColdStartRetry) — 0 keeps this suite fast.
        config(['services.python.cold_start_poll_interval' => 0]);

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

    /** No request at all — matching or not — may be sent to the given URL substring. */
    private function assertNoRequestSentTo(string $urlContains): void
    {
        Http::assertNotSent(fn (HttpClientRequest $request) => str_contains($request->url(), $urlContains));
    }

    #[Test]
    public function preprocess_and_infer_calls_carry_the_auth_header(): void
    {
        Http::fake([
            '*/preprocess*' => Http::response(['section_scores' => []], 200),
            '*/infer' => Http::response($this->fakeInferResponse(), 200),
        ]);

        $service = app(MlService::class);
        $result = $service->runPipeline($this->senior, $this->survey, force: true);

        $this->assertNotNull($result);

        $this->assertHeaderSentTo('/preprocess');
        $this->assertHeaderSentTo('/infer');
        $this->assertNoRequestSentTo('/health');
        Http::assertSent(fn (HttpClientRequest $request): bool => str_contains($request->url(), '/preprocess?defer_reduction=1'));
    }

    #[Test]
    public function batch_preprocess_and_batch_infer_calls_carry_the_auth_header(): void
    {
        Http::fake([
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
        $this->assertNoRequestSentTo('/health');
    }

    #[Test]
    public function preprocess_does_not_retry_after_a_connection_timeout_and_degrades_to_fallback(): void
    {
        // Force the local-Python fallback branch off too (runLocalPythonStage()
        // fails fast against a nonexistent executable), so this test exercises
        // exactly what changed — one attempt, no retry — without depending on
        // whether this machine happens to have python/venv installed.
        putenv('PYTHON_EXECUTABLE=nonexistent-python-for-tests');

        $calls = 0;
        // Http::assertSent() only sees requests that produced a response —
        // one whose fake handler throws isn't recorded — so the header is
        // captured directly from the outgoing request instead.
        $capturedRequest = null;

        Http::fake([
            '*/preprocess*' => function (HttpClientRequest $request) use (&$calls, &$capturedRequest) {
                $calls++;
                $capturedRequest = $request;
                // A client-side read timeout used to be retried by
                // postWithColdStartRetry() — treated the same as a "still
                // starting" 502/503/504 signal. It no longer is (see that
                // method's docblock): a sleeping Render service is up and
                // answers instantly, so a timeout means the OPPOSITE — the
                // service is genuinely busy — and retrying doubled load on
                // an already-slow instance instead of helping. It's rethrown
                // immediately so the caller degrades to fallback scoring.
                throw new ConnectionException('Connection timed out after 5000ms');
            },
            '*/infer' => Http::response($this->fakeInferResponse(), 200),
        ]);

        try {
            $service = app(MlService::class);
            $result = $service->runPipeline($this->senior, $this->survey, force: true);

            $this->assertNotNull($result, 'Pipeline should still produce a (fallback) result, not fail outright.');
            $this->assertSame(1, $calls, 'A client-side timeout must not be retried — exactly one preprocess attempt should be made.');

            $this->assertNotNull($capturedRequest);
            $this->assertSame(
                self::TEST_TOKEN,
                $capturedRequest->header('X-Internal-Api-Key')[0] ?? null,
                'Expected X-Internal-Api-Key header on the preprocess attempt.'
            );
            $this->assertNoRequestSentTo('/health');
        } finally {
            putenv('PYTHON_EXECUTABLE');
        }
    }

    #[Test]
    public function preprocess_retries_after_a_503_and_still_carries_the_auth_header(): void
    {
        // This is Render's actual free-tier behaviour: a sleeping service's
        // edge answers 503 almost instantly (it does not hang the
        // connection) while the container wakes up. postWithColdStartRetry()
        // must poll through this, not just a hung-connection timeout.
        $calls = 0;

        Http::fake([
            '*/preprocess*' => function () use (&$calls) {
                $calls++;

                return $calls === 1
                    ? Http::response('', 503, ['Retry-After' => '1'])
                    : Http::response(['section_scores' => []], 200);
            },
            '*/infer' => Http::response($this->fakeInferResponse(), 200),
        ]);

        $service = app(MlService::class);
        $result = $service->runPipeline($this->senior, $this->survey, force: true);

        $this->assertNotNull($result);
        $this->assertSame(2, $calls, 'Expected the preprocess call to be retried once after a simulated 503.');

        $this->assertHeaderSentTo('/preprocess');
        $this->assertNoRequestSentTo('/health');
    }

    #[Test]
    public function empty_token_configuration_sends_no_auth_header(): void
    {
        config(['services.python.token' => null]);

        Http::fake([
            '*/preprocess*' => Http::response(['section_scores' => []], 200),
            '*/infer' => Http::response($this->fakeInferResponse(), 200),
        ]);

        $service = app(MlService::class);
        $result = $service->runPipeline($this->senior, $this->survey, force: true);

        $this->assertNotNull($result);

        $this->assertNoHeaderSentTo('/preprocess');
        $this->assertNoHeaderSentTo('/infer');
        $this->assertNoRequestSentTo('/health');
    }
}
