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

        // Do not call startServices() here — it blocks for up to 10 minutes waiting
        // for Flask to start, causing the UI to appear hung. If Flask is already up
        // (the normal case when a user triggers re-run from the browser), runPipeline()
        // uses fast HTTP mode automatically. If Flask is not up, runPipeline() falls
        // back to local Python subprocess without needing to wait.
        try {
            $ml->runPipeline($senior, $survey);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('ML pipeline failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
