<?php

namespace App\Jobs;

use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use App\Support\SeniorDataVersion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunMlPipeline implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(
        public readonly int $seniorId,
        public readonly int $surveyId,
    ) {
        // Same reasoning as ProcessMlSingle: this runs a single senior's
        // first analysis right after QoL survey submission (QolSurveyForm.php)
        // — a user is waiting on it, so it shouldn't queue behind heavier
        // background work like the GIS auto-chain on the `default` queue.
        $this->onQueue('ml');
    }

    public function handle(MlService $ml): void
    {
        $senior = SeniorCitizen::findOrFail($this->seniorId);
        $survey = QolSurvey::findOrFail($this->surveyId);

        $ml->runPipeline($senior, $survey);

        // See ProcessMlBatch::handle() for why this lives here rather than
        // in MlService — dashboard/report caches are keyed on this stamp and
        // MlService itself never bumps it.
        SeniorDataVersion::bump();
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RunMlPipeline job failed', [
            'senior_id' => $this->seniorId,
            'survey_id' => $this->surveyId,
            'error' => $e->getMessage(),
        ]);

        // Matching clear for the failure path — see ProcessMlSingle::failed().
        // MlService::persistResults() clears ml_queued_at on success; without
        // this, a genuine failure leaves the profile showing "still
        // processing" forever.
        SeniorCitizen::whereKey($this->seniorId)->update(['ml_queued_at' => null]);
    }
}
