<?php

namespace Tests\Unit;

use App\Services\MlService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MlServiceCronDrainTest extends TestCase
{
    #[Test]
    public function cron_drain_uses_a_bounded_cold_start_budget_without_changing_normal_requests(): void
    {
        config([
            'services.python.cold_start_timeout' => 180,
            'services.python.cron_cold_start_timeout' => 8,
        ]);

        $this->assertSame(180, MlService::coldStartTimeoutForCurrentContext());

        MlService::runInCronDrain(function (): void {
            $this->assertTrue(MlService::isCronDrainActive());
            $this->assertSame(8, MlService::coldStartTimeoutForCurrentContext());
        });

        $this->assertFalse(MlService::isCronDrainActive());
        $this->assertSame(180, MlService::coldStartTimeoutForCurrentContext());
    }
}
