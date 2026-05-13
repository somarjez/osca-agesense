<?php

namespace App\Jobs;

use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunMlPipeline implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries   = 2;

    public function __construct(
        public readonly int $seniorId,
        public readonly int $surveyId,
    ) {}

    public function handle(MlService $ml): void
    {
        $senior = SeniorCitizen::findOrFail($this->seniorId);
        $survey = QolSurvey::findOrFail($this->surveyId);

        $ml->runPipeline($senior, $survey);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RunMlPipeline job failed', [
            'senior_id' => $this->seniorId,
            'survey_id' => $this->surveyId,
            'error'     => $e->getMessage(),
        ]);
    }
}
