<?php

namespace Tests\Feature;

use App\Services\MlService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MlService::pingToWake() replaced a single 5s/3s inline GET (found in
 * production to be genuinely insufficient to wake a fully-cold Render
 * free-tier container) with a patient, round-robin retry loop. These tests
 * exercise the retry/deadline logic directly against Http::fake() — real
 * network timing isn't involved, but the loop's own sleep(5) between rounds
 * is real, so tests here take a few seconds each by design.
 */
class MlPingToWakeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.python.preprocess_url' => 'https://preprocess.test']);
        config(['services.python.inference_url' => 'https://inference.test']);
    }

    #[Test]
    public function both_already_warm_returns_true_immediately(): void
    {
        Http::fake([
            'preprocess.test/health' => Http::response(['status' => 'ok'], 200),
            'inference.test/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $result = app(MlService::class)->pingToWake(deadlineSeconds: 30);

        $this->assertSame(['preprocess' => true, 'inference' => true], $result);
        Http::assertSentCount(2);
    }

    #[Test]
    public function a_service_that_wakes_on_the_second_attempt_still_reports_true(): void
    {
        Http::fake([
            'preprocess.test/health' => Http::sequence()
                ->push(['status' => 'booting'], 503)
                ->push(['status' => 'ok'], 200),
            'inference.test/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $result = app(MlService::class)->pingToWake(deadlineSeconds: 30);

        $this->assertSame(['preprocess' => true, 'inference' => true], $result);
    }

    #[Test]
    public function a_service_that_never_responds_reports_false_once_the_deadline_passes(): void
    {
        Http::fake([
            'preprocess.test/health' => Http::response(['status' => 'booting'], 503),
            'inference.test/health' => Http::response(['status' => 'ok'], 200),
        ]);

        // Short deadline keeps this test fast — the loop's internal sleep(5)
        // still runs once, so this takes a few seconds regardless.
        $result = app(MlService::class)->pingToWake(deadlineSeconds: 1);

        $this->assertSame(['preprocess' => false, 'inference' => true], $result);
    }
}
