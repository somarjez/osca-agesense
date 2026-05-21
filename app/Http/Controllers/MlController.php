<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMlBatch;
use App\Jobs\ProcessMlSingle;
use App\Models\MlResult;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MlController extends Controller
{
    public function __construct(protected MlService $ml) {}

    public function status()
    {
        $health = $this->ml->healthCheck();

        $activeLatestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->whereHas('seniorCitizen', fn($q) => $q->active())
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        $sourceCounts = MlResult::whereIn('id', $activeLatestIds)
            ->select('prediction_source', DB::raw('COUNT(*) as cnt'))
            ->groupBy('prediction_source')
            ->pluck('cnt', 'prediction_source')
            ->toArray();

        $stats = [
            'total_processed'    => MlResult::whereIn('id', $activeLatestIds)->count(),
            'last_run'           => MlResult::whereIn('id', $activeLatestIds)->latest()->value('processed_at'),
            'urgent_count'       => MlResult::whereIn('id', $activeLatestIds)->where('priority_flag', 'urgent')->count(),
            'critical_count'     => MlResult::whereIn('id', $activeLatestIds)->where('critical_flag', true)->count(),
            'unprocessed'        => SeniorCitizen::active()->whereDoesntHave('mlResults')->count(),
            'notebook_cache'     => $sourceCounts['notebook_cache'] ?? 0,
            'live_model'         => $sourceCounts['live_model']     ?? 0,
            'fallback'           => $sourceCounts['fallback']        ?? 0,
            'model_version'      => MlResult::whereIn('id', $activeLatestIds)->value('model_version') ?? '—',
            'umap_mode'          => 'Frozen Transform Only',
            'active_ml_mode'     => env('ENABLE_NOTEBOOK_OVERRIDES', false) ? 'Notebook Overrides Enabled' : 'Live Model Only',
        ];

        return view('ml.status', compact('health', 'stats'));
    }

    public function startServices()
    {
        $success = $this->ml->startServices();
        Cache::forget('ml_nav_health');
        return back()->with(
            $success ? 'success' : 'error',
            $success
                ? 'Python ML services started successfully.'
                : 'Failed to start ML services. Check storage/logs/preprocess.err.log for details.'
        );
    }

    public function batchIndex()
    {
        $totalEligible = SeniorCitizen::active()->whereHas('latestQolSurvey')->count();

        $pending = SeniorCitizen::active()
            ->whereHas('latestQolSurvey')
            ->with(['latestQolSurvey', 'latestMlResult'])
            ->paginate(25)
            ->withQueryString();

        return view('ml.batch', compact('pending', 'totalEligible'));
    }

    /**
     * Dispatch batch ML analysis as queued jobs — returns immediately with a batch ID.
     * The queue worker processes chunks in the background; poll batchStatus() for progress.
     */
    public function batchRun(Request $request)
    {
        $cacheKey = 'ml_batch_' . now()->format('YmdHis');

        $seniorIds = SeniorCitizen::active()
            ->whereHas('latestQolSurvey', fn($q) => $q->where('status', 'processed'))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if (empty($seniorIds)) {
            return response()->json(['error' => 'No eligible seniors found.'], 422);
        }

        $chunks = array_chunk($seniorIds, 100);
        $jobs   = array_map(fn($chunk) => new ProcessMlBatch($chunk, $cacheKey), $chunks);

        $batch = Bus::batch($jobs)
            ->name('ML Batch — ' . now()->format('Y-m-d H:i'))
            ->allowFailures()
            ->dispatch();

        Cache::put("{$cacheKey}:batch_id",  $batch->id,        now()->addHours(2));
        Cache::put("{$cacheKey}:total",     count($seniorIds), now()->addHours(2));
        Cache::put("{$cacheKey}:processed", 0,                 now()->addHours(2));
        Cache::put("{$cacheKey}:failed",    0,                 now()->addHours(2));

        return response()->json([
            'queued'    => true,
            'batch_id'  => $batch->id,
            'cache_key' => $cacheKey,
            'total'     => count($seniorIds),
        ]);
    }

    /**
     * Return progress for a running batch job — polled by the batch view.
     */
    public function batchStatus(Request $request)
    {
        $cacheKey = $request->input('cache_key');
        $batchId  = $request->input('batch_id');

        if (!$cacheKey || !$batchId) {
            return response()->json(['error' => 'Missing parameters.'], 422);
        }

        $batch = Bus::findBatch($batchId);

        if (!$batch) {
            return response()->json(['error' => 'Batch not found.'], 404);
        }

        $total     = (int) Cache::get("{$cacheKey}:total",     $batch->totalJobs * 100);
        $processed = (int) Cache::get("{$cacheKey}:processed", 0);
        $failed    = (int) Cache::get("{$cacheKey}:failed",    0);

        // When the batch is finished the cache counters may lag the last job's
        // increment (file cache is not atomic). Use the batch's own counters as
        // the authoritative final values so the completion message is accurate.
        if ($batch->finished()) {
            $failed    = $batch->failedJobs;
            $processed = max($processed, $total - $failed);
        }

        return response()->json([
            'finished'     => $batch->finished(),
            'cancelled'    => $batch->cancelled(),
            'total'        => $total,
            'processed'    => $processed,
            'failed'       => $failed,
            'pending_jobs' => $batch->pendingJobs,
            'progress'     => $total > 0 ? round($processed / $total * 100) : 0,
        ]);
    }

    public function runSingle(SeniorCitizen $senior)
    {
        $survey = $senior->latestQolSurvey;

        if (!$survey) {
            return response()->json(['error' => 'No QoL survey found for this senior.'], 422);
        }

        ProcessMlSingle::dispatch($senior->id, $survey->id);

        return response()->json(['queued' => true]);
    }

    /**
     * Return the current ML result for a senior — used for polling after dispatch.
     * processed_at is a Unix timestamp so JS can compare numbers regardless of timezone.
     */
    public function resultStatus(SeniorCitizen $senior)
    {
        $result = $senior->latestMlResult;

        if (!$result) {
            return response()->json(['ready' => false]);
        }

        return response()->json([
            'ready'          => true,
            'risk_level'     => $result->overall_risk_level,
            'cluster_name'   => $result->cluster_name,
            'composite_risk' => $result->composite_risk,
            'processed_at'   => $result->processed_at?->timestamp,
        ]);
    }
}
