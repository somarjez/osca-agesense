<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMlBatch;
use App\Models\MlResult;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Bus\Batch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MlController extends Controller
{
    public function __construct(protected MlService $ml) {}

    public function status()
    {
        $health = $this->ml->healthCheck();

        $stats = [
            'total_processed' => MlResult::count(),
            'last_run'        => MlResult::latest()->value('processed_at'),
            'urgent_count'    => MlResult::whereIn('id',
                MlResult::select(DB::raw('MAX(id)'))->groupBy('senior_citizen_id')
            )->where('priority_flag', 'urgent')->count(),
            'unprocessed'     => SeniorCitizen::active()
                ->whereDoesntHave('mlResults')
                ->count(),
        ];

        return view('ml.status', compact('health', 'stats'));
    }

    public function startServices()
    {
        $success = $this->ml->startServices();
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

        Cache::put("{$cacheKey}:batch_id",  $batch->id,     now()->addHours(2));
        Cache::put("{$cacheKey}:total",      count($seniorIds), now()->addHours(2));
        Cache::put("{$cacheKey}:processed",  0,              now()->addHours(2));
        Cache::put("{$cacheKey}:failed",     0,              now()->addHours(2));

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

        $processed = (int) Cache::get("{$cacheKey}:processed", 0);
        $failed    = (int) Cache::get("{$cacheKey}:failed",    0);
        $total     = (int) Cache::get("{$cacheKey}:total",     $batch->totalJobs * 100);

        return response()->json([
            'finished'       => $batch->finished(),
            'cancelled'      => $batch->cancelled(),
            'total'          => $total,
            'processed'      => $processed,
            'failed'         => $failed,
            'pending_jobs'   => $batch->pendingJobs,
            'progress'       => $total > 0 ? round($processed / $total * 100) : 0,
        ]);
    }

    /**
     * Spawn a detached background PHP process to run ML for this senior, then return immediately.
     * Writes a .bat launcher: start /b detaches the PHP child so cmd exits immediately,
     * unblocking popen without waiting for the ML job to finish.
     */
    public function runSingle(SeniorCitizen $senior)
    {
        $survey = $senior->latestQolSurvey;

        if (!$survey) {
            return response()->json(['error' => 'No QoL survey found for this senior.'], 422);
        }

        $php     = PHP_BINARY;
        $artisan = base_path('artisan');
        $bat     = storage_path('logs/ml_launch_' . $senior->id . '.bat');

        // start /b detaches the PHP process — cmd.exe exits immediately so popen unblocks.
        // No output redirection: start /b redirection captures nothing from the detached child.
        // Success is determined by the poll checking processed_at in the DB, not log files.
        file_put_contents($bat, implode("\r\n", [
            '@echo off',
            "start /b \"\" \"$php\" \"$artisan\" ml:run-single {$senior->id} {$survey->id}",
            "del /f /q \"%~f0\"",
        ]));

        pclose(popen("cmd /c \"$bat\"", 'r'));

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
