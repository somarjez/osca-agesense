<?php

namespace App\Jobs;

use App\Services\MlService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * MAINTENANCE-ONLY — do NOT dispatch from normal UI batch flow.
 *
 * Runs fix_cluster_distribution.py to realign cluster assignments for live_model
 * and fallback rows. Safe to dispatch manually (e.g. via Artisan tinker) when new
 * seniors accumulate enough live_model rows to warrant a cluster recalibration.
 * notebook_cache rows are protected and will never be overwritten by the script.
 *
 * Dispatch example:
 *   RecalculateClusters::dispatch('manual_' . now()->format('YmdHis'));
 */
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
