<?php

namespace App\Jobs;

use App\Models\SeniorCitizen;
use App\Services\MlService;
use App\Support\SeniorDataVersion;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ProcessMlBatch implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 300;

    // Was 1 — allowFailures() on the batch means a single failed attempt
    // dropped every senior in the chunk permanently (see failed()'s
    // ml_queued_at clear: nothing re-queues them). A second try gives a
    // transient failure (e.g. the inference service still finishing its
    // cold boot) a chance to succeed instead. Still bounded — queue.php's
    // retry_after (600s) stays comfortably above $timeout regardless of
    // try count, so a still-running attempt is never mistaken for stalled.
    public int $tries = 2;

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
        $fallback = count(array_filter(
            $results,
            fn ($r) => $r['success'] && ($r['result']->prediction_source ?? null) === 'fallback'
        ));

        // Accumulate progress atomically into a shared cache key
        Cache::increment("{$this->cacheKey}:processed", $succeeded);
        Cache::increment("{$this->cacheKey}:failed", $failed);
        Cache::increment("{$this->cacheKey}:fallback", $fallback);

        // Every senior in this chunk is now either freshly scored or
        // definitively failed — either way, no longer "queued". Cleared here
        // (rather than solely inside MlService::persistResults()) because
        // runBatchPipeline()'s reusable-result fast path updates an existing
        // MlResult directly without going through persistResults().
        $this->clearQueuedMarker();

        // Dashboard/report caches are keyed on SeniorDataVersion — MlService
        // never bumps it itself, so without this, risk/cluster numbers lag
        // the ml.latest_result_ids cache's own 5-minute TTL on top of each
        // widget's own TTL. Bumped once per chunk, not per senior, since this
        // is a Cache::forever() file write.
        SeniorDataVersion::bump();

        // MlController::unscoredCount() (the batch page's "N senior(s) still
        // awaiting risk assessment" banner) is cached for 60s and nothing
        // else invalidates it — a batch that fully finishes scoring in under
        // a minute left the banner showing the pre-batch count until that
        // TTL happened to expire on its own. Forgetting it here means the
        // very next 3s batch-status poll recomputes a fresh value.
        Cache::forget('ml_unscored_count');
    }

    /**
     * tries=1, so a whole-chunk throw (e.g. runBatchPipeline itself failing,
     * not a per-item failure which is already caught inside it) lands here.
     */
    public function failed(\Throwable $exception): void
    {
        $this->clearQueuedMarker();
        SeniorDataVersion::bump();
    }

    private function clearQueuedMarker(): void
    {
        SeniorCitizen::whereIn('id', $this->seniorIds)->update(['ml_queued_at' => null]);
    }
}
