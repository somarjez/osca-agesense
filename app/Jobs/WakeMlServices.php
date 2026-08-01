<?php

namespace App\Jobs;

use App\Services\MlService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class WakeMlServices implements ShouldQueue
{
    use Queueable;

    // MlService::pingToWake()'s own deadline is 240s; this must comfortably
    // outlast that (the job's timeout would otherwise kill it mid-wait) —
    // set_time_limit(0) in handle() removes PHP's own ceiling separately.
    public int $timeout = 280;

    public int $tries = 1;

    public function __construct()
    {
        // Same queue as the other ML jobs — see ProcessMlSingle for why a
        // dedicated queue matters here too: this can run for minutes, and
        // shouldn't block behind (or be blocked by) unrelated `default`
        // queue work.
        $this->onQueue('ml');
    }

    public function handle(MlService $ml): void
    {
        set_time_limit(0);

        $result = $ml->pingToWake();

        if (in_array(false, $result, true)) {
            Log::warning('WakeMlServices: at least one service did not respond before the deadline', $result);
        }
    }
}
