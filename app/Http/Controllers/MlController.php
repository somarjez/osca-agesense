<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMlBatch;
use App\Jobs\ProcessMlSingle;
use App\Models\ActivityLog;
use App\Models\MlResult;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use App\Support\Concerns\DrainsMlQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MlController extends Controller
{
    use DrainsMlQueue;

    public function __construct(protected MlService $ml) {}

    /**
     * Fresh (uncached) health snapshot — polled every few seconds by the
     * "waking up" indicator on the status page, so it can't lag behind the
     * 15-30s cache ml.nav-health uses for the topbar dot.
     */
    public function wakeStatus()
    {
        return response()->json($this->ml->healthCheck());
    }

    /**
     * Trigger Render waking both sleeping Python services — a single bounded
     * (~15s) synchronous attempt via MlService::wakeAttempt(). Runs
     * server-side, from this container's own network egress, so it wakes
     * Render even when the *client* device's network/browser blocks direct
     * requests to *.onrender.com (the client also fires its own direct
     * fetch() as a bonus fast path, but that one silently does nothing on a
     * device where such a request is blocked — see wakeAttempt()'s
     * docblock). The client re-POSTs this in a loop while waking, so a
     * cold boot gets covered by several bounded attempts in a row rather
     * than one long-held request. `no.time.limit` on this route group
     * means PHP's own execution limit doesn't cut this short.
     */
    public function wake()
    {
        $result = $this->ml->wakeAttempt();

        return response()->json([
            'preprocess' => $result['preprocess'],
            'inference' => $result['inference'],
            'ready' => ! in_array(false, $result, true),
        ]);
    }

    public function status()
    {
        $health = $this->ml->healthCheck();

        $activeLatestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->whereHas('seniorCitizen', fn ($q) => $q->active())
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        $sourceCounts = MlResult::whereIn('id', $activeLatestIds)
            ->select('prediction_source', DB::raw('COUNT(*) as cnt'))
            ->groupBy('prediction_source')
            ->pluck('cnt', 'prediction_source')
            ->toArray();

        $stats = [
            'total_processed' => MlResult::whereIn('id', $activeLatestIds)->count(),
            'last_run' => MlResult::whereIn('id', $activeLatestIds)->latest()->value('processed_at'),
            'urgent_count' => MlResult::whereIn('id', $activeLatestIds)->where('priority_flag', 'urgent')->count(),
            'critical_count' => MlResult::whereIn('id', $activeLatestIds)->where('critical_flag', true)->count(),
            'unprocessed' => SeniorCitizen::active()->whereDoesntHave('mlResults')->count(),
            'notebook_cache' => $sourceCounts['notebook_cache'] ?? 0,
            'live_model' => $sourceCounts['live_model'] ?? 0,
            'fallback' => $sourceCounts['fallback'] ?? 0,
            'model_version' => MlResult::whereIn('id', $activeLatestIds)->value('model_version') ?? '—',
            'umap_mode' => 'Frozen Transform Only',
            'active_ml_mode' => env('ENABLE_NOTEBOOK_OVERRIDES', false) ? 'Notebook Overrides Enabled' : 'Live Model Only',
        ];

        $canControlLocalServices = $this->ml->localServiceControlAvailable();
        $preprocessUrl = $this->ml->preprocessUrl();
        $inferenceUrl = $this->ml->inferenceUrl();

        return view('ml.status', compact('health', 'stats', 'canControlLocalServices', 'preprocessUrl', 'inferenceUrl'));
    }

    public function startServices()
    {
        if (! $this->ml->localServiceControlAvailable()) {
            return back()->with('error', $this->localServiceControlUnavailableMessage());
        }

        $success = $this->ml->startServices();
        Cache::forget('ml_nav_health');

        return back()->with(
            $success ? 'success' : 'error',
            $success
                ? 'Python ML services started successfully.'
                : 'Failed to start ML services. Check storage/logs/preprocess.err.log for details.'
        );
    }

    public function stopServices()
    {
        if (! $this->ml->localServiceControlAvailable()) {
            return back()->with('error', $this->localServiceControlUnavailableMessage());
        }

        $success = $this->ml->stopServices();
        Cache::forget('ml_nav_health');

        return back()->with(
            $success ? 'success' : 'error',
            $success ? 'Python ML services stopped.' : 'Failed to stop ML services cleanly.'
        );
    }

    public function restartServices()
    {
        if (! $this->ml->localServiceControlAvailable()) {
            return back()->with('error', $this->localServiceControlUnavailableMessage());
        }

        $success = $this->ml->restartServices();
        Cache::forget('ml_nav_health');

        return back()->with(
            $success ? 'success' : 'error',
            $success
                ? 'Python ML services restarted successfully.'
                : 'Failed to restart ML services. Check storage/logs/*.err.log for details.'
        );
    }

    /**
     * Defense in depth against a direct POST bypassing the hidden buttons
     * (see ml/status.blade.php) — the .ps1 scripts these actions run are
     * fundamentally Windows-only (MlService::localServiceControlAvailable()),
     * so on Render this can never succeed. The old generic failure message
     * pointed at a log file on a machine this container isn't, which read as
     * a real bug rather than an expected platform limitation.
     */
    private function localServiceControlUnavailableMessage(): string
    {
        return 'Start/Stop/Restart controls are only available in local development. '
            .'On the hosted deployment, the ML services run as independent Render '
            .'services managed from the Render dashboard.';
    }

    public function batchIndex(Request $request)
    {
        // $totalEligible: all seniors with any QoL survey (shown in the table/pagination)
        // $totalReady: subset with status='processed' — what batchRun() will actually process
        // Keeping both avoids the UI showing "Run Batch (286)" when only 283 will run.
        $totalEligible = SeniorCitizen::active()->whereHas('latestQolSurvey')->count();
        $totalReady = SeniorCitizen::active()
            ->whereHas('latestQolSurvey', fn ($q) => $q->where('status', 'processed'))
            ->count();

        $pending = SeniorCitizen::active()
            ->whereHas('latestQolSurvey')
            ->with(['latestQolSurvey', 'latestMlResult'])
            ->when($request->search, fn ($q, $term) => $q->where(function ($w) use ($term) {
                $w->where('osca_id', 'like', "%{$term}%")
                    ->orWhere('official_osca_id', 'like', "%{$term}%")
                    ->orWhereRaw("LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?", ['%'.strtolower($term).'%']);
            }))
            ->paginate(25)
            ->withQueryString();

        return view('ml.batch', compact('pending', 'totalEligible', 'totalReady'))
            ->with('lastBatchRun', Cache::get('ml_last_batch_started'))
            ->with('lastBatchCount', Cache::get('ml_last_batch_senior_count'))
            ->with('resumeBatch', $this->resumableBatch())
            ->with('unscoredCount', $this->unscoredCount());
    }

    /**
     * Active seniors with a processed survey but no fresh MlResult — the
     * count that used to silently stay wrong after a large bulk upload or
     * batch run outlived every queue-drain time budget (see
     * RequeueUnscoredSeniors). This is a display-only approximation of
     * MlService::findReusableResult()'s "missing or stale" cases, not a
     * full replica of it (it doesn't check model_version/fallback-upgrade
     * — those are rarer and the sweeper itself is the authoritative check),
     * cached briefly since it backs the 3s batch-status poll.
     */
    private function unscoredCount(): int
    {
        return Cache::remember('ml_unscored_count', 60, function () {
            return SeniorCitizen::active()
                ->whereHas('latestQolSurvey', function ($q) {
                    $q->where('status', 'processed')
                        ->where(function ($q2) {
                            $q2->whereDoesntHave('mlResult')
                                ->orWhereHas('mlResult', fn ($q3) => $q3->where('is_stale', true));
                        });
                })
                ->count();
        });
    }

    /**
     * The batch page's progress panel only has state while its own tab is
     * open — reload it (or open it fresh, e.g. after a bulk upload redirect)
     * while a batch is still running and it used to fall back to the idle
     * "Run Full Batch" button with no sign anything was in flight. Surfaces
     * the in-progress batch's cache_key/batch_id/total so the view can
     * re-seed its Alpine state and resume polling instead.
     */
    private function resumableBatch(): ?array
    {
        $current = Cache::get('ml_current_batch');
        if (! $current) {
            return null;
        }

        $batch = Bus::findBatch($current['batch_id']);
        if (! $batch || $batch->finished()) {
            // Stale entry batchStatus() hasn't cleared yet (e.g. the tab that
            // was polling it was closed before the batch actually finished).
            Cache::forget('ml_current_batch');

            return null;
        }

        return [
            'cache_key' => $current['cache_key'],
            'batch_id' => $current['batch_id'],
            // Matches batchRun()'s chunk size config — this is only an
            // estimate for the rare case where the ":total" key itself has
            // expired/is missing; the real total from that key wins otherwise.
            'total' => (int) Cache::get("{$current['cache_key']}:total", $batch->totalJobs * (int) config('services.python.batch_chunk_size', 25)),
        ];
    }

    /**
     * Dispatch batch ML analysis as queued jobs — returns immediately with a batch ID.
     * The queue worker processes chunks in the background; poll batchStatus() for progress.
     */
    public function batchRun(Request $request)
    {
        $cacheKey = 'ml_batch_'.now()->format('YmdHis');

        $seniorIds = SeniorCitizen::active()
            ->whereHas('latestQolSurvey', fn ($q) => $q->where('status', 'processed'))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if (empty($seniorIds)) {
            return response()->json(['error' => 'No eligible seniors found.'], 422);
        }

        ActivityLog::record('ml_batch_triggered', auth()->user(), 'Batch ML analysis triggered for '.count($seniorIds).' seniors');

        // Same reload-survival marker as runSingle() — see that method's
        // comment. Bulk query-builder update, not $senior->update(), so it's
        // one statement for the whole batch.
        SeniorCitizen::whereIn('id', $seniorIds)->update(['ml_queued_at' => now()]);

        $chunks = array_chunk($seniorIds, (int) config('services.python.batch_chunk_size', 25));
        $jobs = array_map(fn ($chunk) => new ProcessMlBatch($chunk, $cacheKey), $chunks);

        $batch = Bus::batch($jobs)
            ->name('ML Batch — '.now()->format('Y-m-d H:i'))
            ->allowFailures()
            ->dispatch();

        Cache::put("{$cacheKey}:batch_id", $batch->id, now()->addHours(2));
        Cache::put("{$cacheKey}:total", count($seniorIds), now()->addHours(2));
        Cache::put("{$cacheKey}:processed", 0, now()->addHours(2));
        Cache::put("{$cacheKey}:failed", 0, now()->addHours(2));
        Cache::put("{$cacheKey}:fallback", 0, now()->addHours(2));

        // NEW — record when the last full batch was started, shown on the batch page.
        Cache::put('ml_last_batch_started', now(), now()->addDays(90));
        Cache::put('ml_last_batch_senior_count', count($seniorIds), now()->addDays(90));

        // Lets batchIndex() re-seed the progress panel if this browser tab (or
        // a different one) reloads/reopens the batch page before the batch
        // finishes — see batchIndex() and batchStatus(), which clears this
        // once Bus::findBatch()->finished() is true.
        Cache::put('ml_current_batch', ['cache_key' => $cacheKey, 'batch_id' => $batch->id], now()->addHours(2));

        // See drainQueueAfterResponse() — widened from 60s so more chunks of
        // a large batch finish inline instead of waiting on the next cron
        // tick; batchStatus() below also tops this up while the progress
        // page is being actively polled. Anything still left over is picked
        // up by the next cron tick regardless.
        $this->drainQueueAfterResponse(150);

        return response()->json([
            'queued' => true,
            'batch_id' => $batch->id,
            'cache_key' => $cacheKey,
            'total' => count($seniorIds),
        ]);
    }

    /**
     * Return progress for a running batch job — polled by the batch view.
     */
    public function batchStatus(Request $request)
    {
        $cacheKey = $request->input('cache_key');
        $batchId = $request->input('batch_id');

        if (! $cacheKey || ! $batchId) {
            return response()->json(['error' => 'Missing parameters.'], 422);
        }

        $batch = Bus::findBatch($batchId);

        if (! $batch) {
            return response()->json(['error' => 'Batch not found.'], 404);
        }

        $total = (int) Cache::get("{$cacheKey}:total", $batch->totalJobs * (int) config('services.python.batch_chunk_size', 25));
        $processed = (int) Cache::get("{$cacheKey}:processed", 0);
        $failed = (int) Cache::get("{$cacheKey}:failed", 0);
        $fallback = (int) Cache::get("{$cacheKey}:fallback", 0);

        // When the batch is finished the cache counters may lag the last job's
        // increment (file cache is not atomic). Use the batch's own counters as
        // the authoritative final values so the completion message is accurate.
        if ($batch->finished()) {
            $failed = $batch->failedJobs;
            $processed = max($processed, $total - $failed);

            // Batch is done — stop offering it as resumable on a fresh page
            // load. Only clear if it's still this batch (a newer run may
            // already have overwritten the key by the time this poll lands).
            $current = Cache::get('ml_current_batch');
            if (($current['batch_id'] ?? null) === $batchId) {
                Cache::forget('ml_current_batch');
            }
        } else {
            // The progress view polls this every 3s while a batch runs, which
            // is effectively free traffic to nudge the queue along instead of
            // waiting on the next cron tick. drainQueueAfterResponse() now
            // owns its own cross-request lock (see DrainsMlQueue), so an
            // overlapping poll that arrives while a drain is still running
            // simply no-ops instead of stacking a second queue:work process
            // on top of it — no separate throttle needed here. 60s (up from
            // 10s) gives a single Render free-tier chunk (~35-40s observed)
            // room to actually finish inside one drain instead of being cut
            // off mid-job and left reserved for the full retry_after window.
            $this->drainQueueAfterResponse(60);
        }

        return response()->json([
            'finished' => $batch->finished(),
            'cancelled' => $batch->cancelled(),
            'total' => $total,
            'processed' => $processed,
            'failed' => $failed,
            'fallback' => $fallback,
            'pending_jobs' => $batch->pendingJobs,
            'progress' => $total > 0 ? round($processed / $total * 100) : 0,
            // Lets the view keep showing "N still awaiting risk assessment"
            // as it falls while this batch runs, and confirm it reached 0
            // once finished — rather than trusting this batch's own
            // processed/total, which can't see seniors outside it (e.g. a
            // second bulk upload started mid-run, or stragglers this batch
            // itself doesn't cover).
            'unscored' => $this->unscoredCount(),
        ]);
    }

    public function runSingle(SeniorCitizen $senior)
    {
        $survey = $senior->latestQolSurvey;

        if (! $survey) {
            return response()->json(['error' => 'No QoL survey found for this senior.'], 422);
        }

        ActivityLog::record('ml_run_triggered', $senior, "ML analysis triggered for {$senior->full_name}");

        // Persists across reload (see 2026_07_30_182716_add_ml_queued_at...)
        // — client-side poll state alone can't survive a page reload, and on
        // Render's free tier the queue only drains every ~10 min (Phase E),
        // so the poll will routinely outlive the page. Cleared by
        // MlService::persistResults() on success or ProcessMlSingle::failed()
        // on failure.
        $senior->update(['ml_queued_at' => now()]);

        ProcessMlSingle::dispatch($senior->id, $survey->id);

        // See drainQueueAfterResponse() — a single senior's assessment
        // normally finishes well inside 20s.
        $this->drainQueueAfterResponse(20);

        return response()->json(['queued' => true]);
    }

    /**
     * Return the current ML result for a senior — used for polling after dispatch.
     * processed_at is a Unix timestamp so JS can compare numbers regardless of timezone.
     */
    public function resultStatus(SeniorCitizen $senior)
    {
        $result = $senior->latestMlResult;

        if (! $result) {
            return response()->json(['ready' => false]);
        }

        return response()->json([
            'ready' => true,
            'risk_level' => $result->overall_risk_level,
            'cluster_name' => $result->cluster_name,
            'composite_risk' => $result->composite_risk,
            'processed_at' => $result->processed_at?->timestamp,
            'prediction_source' => $result->prediction_source,
        ]);
    }
}
