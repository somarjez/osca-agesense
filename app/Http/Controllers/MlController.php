<?php

namespace App\Http\Controllers;

use App\Models\MlResult;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Http\Request;
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
     * Batch ML analysis — processes all eligible seniors in bulk.
     * Uses runBatchPipeline() to spawn one Python process per chunk
     * instead of two processes per senior.
     */
    public function batchRun(Request $request)
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ignore_user_abort(true);

        // Bring Flask services up before processing so runBatchPipeline() uses
        // fast HTTP mode (2 HTTP calls for all seniors) instead of spawning a
        // local Python subprocess that cold-starts UMAP + models per chunk.
        $this->ml->startServices();

        $success = 0;
        $failed  = 0;
        $errors  = [];

        SeniorCitizen::active()
            ->whereHas('latestQolSurvey', fn($q) => $q->where('status', 'processed'))
            ->with(['latestQolSurvey'])
            ->orderBy('id')
            ->chunk(100, function ($seniors) use (&$success, &$failed, &$errors) {
                // Build items array for batch pipeline
                $items = $seniors->map(fn($s) => [
                    'senior' => $s,
                    'survey' => $s->latestQolSurvey,
                ])->all();

                $results = $this->ml->runBatchPipeline($items);

                foreach ($results as $i => $entry) {
                    if ($entry['success']) {
                        $success++;
                    } else {
                        $failed++;
                        if (count($errors) < 5) {
                            $name = $items[$i]['senior']->full_name ?? "Senior #{$i}";
                            $errors[] = "{$name}: " . ($entry['error'] ?? 'Unknown error');
                        }
                    }
                }
            });

        $message = "Batch complete. Processed: {$success}. Failed: {$failed}."
            . ($errors ? ' Errors: ' . implode('; ', $errors) : '');

        if ($request->expectsJson()) {
            return response()->json([
                'success'   => $failed === 0,
                'processed' => $success,
                'failed'    => $failed,
                'message'   => $message,
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * Spawn a detached background PHP process to run ML for this senior, then return immediately.
     * Uses cmd /c start /b so popen returns instantly without waiting for the ML job to finish.
     */
    public function runSingle(SeniorCitizen $senior)
    {
        $survey = $senior->latestQolSurvey;

        if (!$survey) {
            return response()->json(['error' => 'No QoL survey found for this senior.'], 422);
        }

        $php     = PHP_BINARY;
        $artisan = base_path('artisan');
        $outLog  = storage_path('logs/ml_single_' . $senior->id . '.log');
        $errLog  = storage_path('logs/ml_single_' . $senior->id . '.err.log');

        // Use cmd /c start /b to fire-and-forget the PHP process.
        // This avoids PowerShell argument parsing issues with spaces in paths.
        // start /b runs the command in the background without opening a new window.
        $cmd = "cmd /c start /b \"\" \"$php\" \"$artisan\" ml:run-single {$senior->id} {$survey->id}"
            . " > \"$outLog\" 2> \"$errLog\"";

        pclose(popen($cmd, 'r'));

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
