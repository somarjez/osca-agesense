<?php

namespace App\Jobs;

use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ProcessMlBatch implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    /**
     * @param  array<int>  $seniorIds  IDs of seniors to process in this chunk
     * @param  string  $cacheKey  Cache key shared across all chunks for progress tracking
     */
    public function __construct(
        public readonly array $seniorIds,
        public readonly string $cacheKey,
    ) {}

    public function handle(MlService $ml): void
    {
        set_time_limit(0);

        if ($this->batch()?->cancelled()) {
            $this->clearQueuedMarker();

            return;
        }

        $seniors = SeniorCitizen::active()
            ->whereIn('id', $this->seniorIds)
            ->with('latestQolSurvey')
            ->get();

        $items = $seniors
            ->filter(fn ($s) => $s->latestQolSurvey !== null)
            ->map(fn ($s) => ['senior' => $s, 'survey' => $s->latestQolSurvey])
            ->values()
            ->all();

        if (empty($items)) {
            $this->clearQueuedMarker();

            return;
        }

        $results = $ml->runBatchPipeline($items);

        $succeeded = count(array_filter($results, fn ($r) => $r['success']));
        $failed = count($results) - $succeeded;

        // Accumulate progress atomically into a shared cache key
        Cache::increment("{$this->cacheKey}:processed", $succeeded);
        Cache::increment("{$this->cacheKey}:failed", $failed);

        // Every senior in this chunk is now either freshly scored or
        // definitively failed — either way, no longer "queued". Cleared here
        // (rather than solely inside MlService::persistResults()) because
        // runBatchPipeline()'s reusable-result fast path updates an existing
        // MlResult directly without going through persistResults().
        $this->clearQueuedMarker();
    }

    /**
     * tries=1, so a whole-chunk throw (e.g. runBatchPipeline itself failing,
     * not a per-item failure which is already caught inside it) lands here.
     */
    public function failed(\Throwable $exception): void
    {
        $this->clearQueuedMarker();
    }

    private function clearQueuedMarker(): void
    {
        SeniorCitizen::whereIn('id', $this->seniorIds)->update(['ml_queued_at' => null]);
    }
}
