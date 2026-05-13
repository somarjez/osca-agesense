<?php

namespace App\Jobs;

use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMlSingle implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries   = 1;

    public function __construct(
        public readonly int $seniorId,
        public readonly int $surveyId,
    ) {}

    public function handle(MlService $ml): void
    {
        set_time_limit(0);

        $senior = SeniorCitizen::find($this->seniorId);
        $survey = QolSurvey::find($this->surveyId);

        if (!$senior || !$survey) {
            return;
        }

        $ml->runPipeline($senior, $survey);
    }
}
