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
            'critical_count'  => MlResult::whereIn('id',
                MlResult::select(DB::raw('MAX(id)'))->groupBy('senior_citizen_id')
            )->where('overall_risk_level', 'CRITICAL')->count(),
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
     * Run ML inference for a single senior citizen.
     * Uses combined local mode (one subprocess) to prevent timeout errors.
     */
    public function runSingle(SeniorCitizen $senior)
    {
        // Prevent PHP from killing the process mid-run while Python loads models
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        $survey = $senior->latestQolSurvey;

        if (!$survey) {
            return response()->json(['error' => 'No QoL survey found.'], 422);
        }

        try {
            $result = $this->ml->runPipeline($senior, $survey);
            return response()->json([
                'success'        => true,
                'risk_level'     => $result->overall_risk_level,
                'cluster_name'   => $result->cluster_name,
                'composite_risk' => $result->composite_risk,
            ]);
        } catch (\Exception $e) {
            Log::error('ML runSingle failed', [
                'senior_id' => $senior->id,
                'error'     => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
