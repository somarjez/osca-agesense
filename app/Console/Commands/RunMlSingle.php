<?php

namespace App\Console\Commands;

use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Console\Command;

class RunMlSingle extends Command
{
    protected $signature   = 'ml:run-single {seniorId} {surveyId}';
    protected $description = 'Run ML pipeline for a single senior (called as a detached background process).';

    public function handle(MlService $ml): int
    {
        $senior = SeniorCitizen::find($this->argument('seniorId'));
        $survey = QolSurvey::find($this->argument('surveyId'));

        if (!$senior || !$survey) {
            $this->error('Senior or survey not found.');
            return self::FAILURE;
        }

        // Attempt to bring Flask services up before running the pipeline.
        // This command runs as a detached background process, so blocking here
        // is fine — the web request already returned immediately.
        // If Flask starts, runPipeline() uses fast HTTP mode (~2s per senior);
        // otherwise runPipeline() falls back to local Python subprocess (~60s cold-start).
        // startServices() waits up to 10 min for venv creation on first run,
        // then polls up to 40s for the health endpoint.
        $ml->startServices();

        try {
            $ml->runPipeline($senior, $survey);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('ML pipeline failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
