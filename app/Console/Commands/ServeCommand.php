<?php

namespace App\Console\Commands;

use App\Services\MlService;
use Illuminate\Foundation\Console\ServeCommand as BaseServeCommand;

class ServeCommand extends BaseServeCommand
{
    public function handle()
    {
        $this->launchMlServicesInBackground();
        return parent::handle();
    }

    private function launchMlServicesInBackground(): void
    {
        $startScript = base_path('python/start_services.ps1');

        if (!is_file($startScript)) {
            $this->warn('  [ML] Python start script not found — skipping auto-start.');
            return;
        }

        $this->info('  <fg=cyan>[ML]</> Starting Python ML services in background (first run may take a few minutes)...');

        // Run the ML startup in a detached background process so the Laravel
        // server starts immediately and request logs appear without delay.
        $logPath = storage_path('logs/ml_serve_startup.log');
        $cmd = 'powershell.exe -NoProfile -File ' . escapeshellarg($startScript)
            . ' > ' . escapeshellarg($logPath) . ' 2>&1';

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start /B ' . $cmd, 'r'));
        } else {
            exec($cmd . ' &');
        }
    }
}
