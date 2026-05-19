<?php

namespace App\Jobs;

use App\Services\MlService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RecalculateClusters implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries   = 1;

    public function __construct(public readonly string $cacheKey) {}

    public function handle(MlService $ml): void
    {
        Cache::put("{$this->cacheKey}:reclustering", true, now()->addHours(1));

        $ml->runRecluster();

        Cache::put("{$this->cacheKey}:reclustering", false, now()->addHours(1));
        Cache::put("{$this->cacheKey}:recluster_done", true, now()->addHours(1));
    }
}
