<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for the keep-alive workflow's curl (28) timeout:
 * CronTickController had no overall wall-clock budget, and queue:work's
 * own --max-time is only checked BETWEEN jobs, so a chunk already running
 * when that check fired could push the whole response past curl's 85s
 * --max-time and nginx's 90s fastcgi_read_timeout. See config/services.php's
 * cron_budget/cron_job_headroom and CronTickController's own docblock.
 */
class CronTickControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cron.token' => 'test-cron-token']);
    }

    private function hit(): TestResponse
    {
        return $this->postJson('/api/internal/cron-tick', [], ['X-Cron-Token' => 'test-cron-token']);
    }

    #[Test]
    public function missing_or_wrong_token_is_forbidden(): void
    {
        $this->postJson('/api/internal/cron-tick')->assertForbidden();
        $this->postJson('/api/internal/cron-tick', [], ['X-Cron-Token' => 'wrong'])->assertForbidden();
    }

    #[Test]
    public function a_normal_tick_runs_schedule_and_drains_the_queue(): void
    {
        $response = $this->hit();

        $response->assertOk();
        $response->assertJsonStructure(['ok', 'ran_at', 'schedule_output', 'queue_output']);
        $response->assertJson(['ok' => true]);
    }

    #[Test]
    public function the_drain_is_skipped_not_the_whole_tick_when_the_budget_is_already_spent(): void
    {
        // A budget of 0 means schedule:run alone (however fast) already
        // consumes the whole tick — the drain must be skipped, not attempted
        // with a zero/negative --max-time, and the tick must still report ok.
        config(['services.python.cron_budget' => 0]);

        $response = $this->hit();

        $response->assertOk();
        $response->assertJson(['ok' => true]);
        $response->assertJsonFragment(['queue_output' => '(skipped — schedule:run used the full tick budget)']);
    }

    #[Test]
    public function the_drain_is_skipped_when_another_drain_already_holds_the_lock(): void
    {
        // Simulates a post-response drain (DrainsMlQueue::drainQueueAfterResponse(),
        // e.g. from a bulk upload or manual batch run) already in progress —
        // the cron tick must not run a second concurrent queue:work against
        // the same single-threaded inference service.
        $lock = Cache::lock('ml:queue-drain', 30);
        $this->assertTrue($lock->get());

        try {
            $response = $this->hit();

            $response->assertOk();
            $response->assertJsonFragment(['queue_output' => '(skipped — another drain already holds the lock)']);
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function the_lock_is_released_after_a_normal_drain_so_a_later_tick_is_not_blocked(): void
    {
        $this->hit()->assertOk();

        // If the first tick's lock leaked, this one would report "skipped —
        // another drain already holds the lock" instead of actually running.
        $second = $this->hit();
        $second->assertOk();
        $second->assertJsonMissing(['queue_output' => '(skipped — another drain already holds the lock)']);
    }
}
