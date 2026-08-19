<?php

namespace App\Jobs;

use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Services\MlService;
use App\Support\SeniorDataVersion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ProcessMlSingle implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public readonly int $seniorId,
        public readonly int $surveyId,
    ) {
        // Dedicated queue, worked by its own process (see start.ps1), so a
        // single senior's re-analysis never waits behind heavier background
        // work (e.g. the GIS auto-chain a barangay edit queues on the
        // `default` queue) on a shared serial worker. Users click "Re-run
        // Assessment" and wait for it. Set in the constructor rather than as
        // a redeclared property — Queueable's own $queue property has no
        // default value, and PHP treats a class property with a different
        // default than the trait's as an incompatible redeclaration.
        $this->onQueue('ml');
    }

    public function handle(MlService $ml): void
    {
        set_time_limit(0);

        $senior = SeniorCitizen::find($this->seniorId);
        $survey = QolSurvey::find($this->surveyId);

        if (! $senior || ! $survey) {
            // Nothing to process (e.g. deleted between dispatch and pickup) —
            // clear the reload-survival marker set in MlController::runSingle()
            // so the profile doesn't show "still processing" forever.
            $senior?->update(['ml_queued_at' => null]);

            return;
        }

        $ml->runPipeline($senior, $survey, force: true);

        // See ProcessMlBatch::handle() for why this lives here rather than
        // in MlService — dashboard/report caches are keyed on this stamp and
        // MlService itself never bumps it.
        SeniorDataVersion::bump();

        // See ProcessMlBatch::handle()'s matching comment — the batch page's
        // "N still awaiting" count is otherwise stale for up to 60s after
        // this senior stops being unscored.
        Cache::forget('ml_unscored_count');
    }

    /**
     * tries=1, so any thrown exception lands here once. MlService::persistResults()
     * clears ml_queued_at on success — this is the matching clear for the
     * failure path, so a real failure doesn't leave a permanent false
     * "still processing" state on the senior's profile.
     */
    public function failed(\Throwable $exception): void
    {
        SeniorCitizen::whereKey($this->seniorId)->update(['ml_queued_at' => null]);
    }
}
