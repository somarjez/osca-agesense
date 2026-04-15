<?php

namespace App\Http\Controllers;

use App\Models\MlResult;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MlController extends Controller
{
    public function __construct(protected MlService $ml) {}

    /**
     * ML service status page.
     */
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

    /**
     * Batch analysis index page.
     */
    public function batchIndex()
    {
        $pending = SeniorCitizen::active()
            ->whereHas('latestQolSurvey')
            ->with(['latestQolSurvey', 'latestMlResult'])
            ->get();

        return view('ml.batch', compact('pending'));
    }

    /**
     * Run batch ML analysis for all seniors with a processed QoL survey.
     */
    public function batchRun(Request $request)
    {
        // Batch inference may take >30s for large datasets.
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
            ->chunk(20, function ($seniors) use (&$success, &$failed, &$errors) {
                foreach ($seniors as $senior) {
                    try {
                        $this->ml->runPipeline($senior, $senior->latestQolSurvey);
                        $success++;
                    } catch (\Exception $e) {
                        $failed++;
                        if (count($errors) < 3) {
                            $errors[] = "{$senior->full_name}: {$e->getMessage()}";
                        }
                    }
                }
            });

        return back()->with('success',
            "Batch complete. Processed: {$success}. Failed: {$failed}."
            . ($errors ? " Errors: " . implode('; ', $errors) : '')
        );
    }

    /**
     * Run ML inference for a single senior citizen.
     */
    public function runSingle(SeniorCitizen $senior)
    {
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
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
