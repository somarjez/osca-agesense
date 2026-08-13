<?php

namespace Tests\Feature;

use App\Services\MlService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MlService::healthCheck() was refactored from a sequential foreach (each
 * service checked one after another, up to healthTimeout+healthConnectTimeout
 * apiece — worst case ~10s for one call) to Http::pool() (both concurrently,
 * bounded by the slower of the two) — see that method's docblock for why:
 * it's on the request path of /ml/nav-health on every cache miss, hit by
 * both the topbar status dot and the global ml-service-guard component.
 *
 * Http::fake() resolves pooled requests synchronously in the test process —
 * it doesn't preserve real concurrent I/O timing — so these tests verify the
 * refactor is behavior-preserving (same output shape, same per-service
 * status classification across all three states) rather than asserting on
 * wall-clock time, which fakes can't meaningfully demonstrate either way.
 */
class MlHealthCheckPoolingTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function both_services_healthy_reports_ok_and_http_mode(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $result = app(MlService::class)->healthCheck();

        $this->assertSame('ok', $result['preprocessor']);
        $this->assertSame('ok', $result['inference']);
        $this->assertSame('http', $result['mode']);
        Http::assertSentCount(2);
    }

    #[Test]
    public function one_service_returning_a_non_2xx_status_is_reported_as_error(): void
    {
        Http::fake([
            '*:5001/health' => Http::response(['status' => 'ok'], 200),
            '*:5002/health' => Http::response('', 500),
        ]);

        $result = app(MlService::class)->healthCheck();

        $this->assertSame('ok', $result['preprocessor']);
        $this->assertSame('error', $result['inference']);
        $this->assertNotSame('http', $result['mode']);
    }

    #[Test]
    public function an_unreachable_service_is_reported_as_unreachable_not_error(): void
    {
        Http::fake([
            '*:5001/health' => Http::response(['status' => 'ok'], 200),
            '*:5002/health' => fn (HttpClientRequest $request) => throw new ConnectException(
                'Connection refused',
                new GuzzleRequest('GET', $request->url())
            ),
        ]);

        $result = app(MlService::class)->healthCheck();

        $this->assertSame('ok', $result['preprocessor']);
        $this->assertSame('unreachable', $result['inference']);
        $this->assertNotSame('http', $result['mode']);
    }

    #[Test]
    public function both_services_down_falls_back_to_php_fallback_mode_when_local_python_unavailable(): void
    {
        Http::fake([
            '*/health' => Http::response('', 503),
        ]);

        $result = app(MlService::class)->healthCheck();

        $this->assertSame('error', $result['preprocessor']);
        $this->assertSame('error', $result['inference']);
        $this->assertContains($result['mode'], ['local_python', 'php_fallback']);
    }

    /**
     * Regression coverage for the "first analysis takes over a minute"
     * report: a 200 from /health only means the port is bound, not that the
     * Python service's model artifacts have finished loading (its own
     * _warm_up_models() background thread can still be running). Before this
     * fix, that in-progress state was indistinguishable from "fully ready" —
     * both reported 'ok', so the wake modal declared success while the next
     * real request still paid the full ~30s cold-load cost itself.
     */
    #[Test]
    public function a_service_that_answers_but_has_not_finished_warming_up_is_reported_as_warming_not_ok(): void
    {
        Http::fake([
            '*:5001/health' => Http::response(['status' => 'ok', 'models_ready' => true], 200),
            '*:5002/health' => Http::response(['status' => 'ok', 'models_ready' => false], 200),
        ]);

        $result = app(MlService::class)->healthCheck();

        $this->assertSame('ok', $result['preprocessor']);
        $this->assertSame('warming', $result['inference']);
        $this->assertNotSame('http', $result['mode'], 'mode must not report http/ready while the inference service is still warming up.');
    }

    /**
     * An older/unpatched service (or any future endpoint without this
     * concept) has no models_ready key at all — must be treated as ready,
     * not block forever on a signal that will never arrive.
     */
    #[Test]
    public function a_health_response_with_no_models_ready_key_is_still_treated_as_ready(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $result = app(MlService::class)->healthCheck();

        $this->assertSame('ok', $result['preprocessor']);
        $this->assertSame('ok', $result['inference']);
        $this->assertSame('http', $result['mode']);
    }
}
