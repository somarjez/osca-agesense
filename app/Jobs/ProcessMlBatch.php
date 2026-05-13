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
    use Queueable, Batchable;

    public int $timeout = 300;
    public int $tries   = 1;

    /**
     * @param array<int> $seniorIds IDs of seniors to process in this chunk
     * @param string     $cacheKey  Cache key shared across all chunks for progress tracking
     */
    public function __construct(
        public readonly array  $seniorIds,
        public readonly string $cacheKey,
    ) {}

    public function handle(MlService $ml): void
    {
        set_time_limit(0);

        if ($this->batch()?->cancelled()) {
            return;
        }

        $seniors = SeniorCitizen::active()
            ->whereIn('id', $this->seniorIds)
            ->with('latestQolSurvey')
            ->get();

        $items = $seniors
            ->filter(fn($s) => $s->latestQolSurvey !== null)
            ->map(fn($s) => ['senior' => $s, 'survey' => $s->latestQolSurvey])
            ->values()
            ->all();

        if (empty($items)) {
            return;
        }

        $results = $ml->runBatchPipeline($items);

        $succeeded = count(array_filter($results, fn($r) => $r['success']));
        $failed    = count($results) - $succeeded;

        // Accumulate progress atomically into a shared cache key
        Cache::increment("{$this->cacheKey}:processed", $succeeded);
        Cache::increment("{$this->cacheKey}:failed",    $failed);
    }
}
