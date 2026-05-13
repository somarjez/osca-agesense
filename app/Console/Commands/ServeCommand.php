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

        $logPath     = storage_path('logs/ml_serve_startup.log');
        $launcherPs1 = storage_path('logs/ml_serve_launch.ps1');

        if (PHP_OS_FAMILY === 'Windows') {
            // Write a .ps1 launcher — paths embedded as double-quoted string literals
            // so spaces in the project path need no shell escaping.
            // Start-Process fully detaches the child from the PHP serve process.
            // popen/start /B keeps a handle open on some Windows configurations,
            // which blocks PHP's request-log output from appearing.
            file_put_contents($launcherPs1,
                "Start-Process powershell.exe"
                . " -ArgumentList @('-NoProfile', '-NonInteractive', '-File', \"$startScript\")"
                . " -RedirectStandardOutput \"$logPath\""
                . " -RedirectStandardError \"$logPath\""
                . " -WindowStyle Hidden\n"
                . "Remove-Item -LiteralPath \"$launcherPs1\" -ErrorAction SilentlyContinue\n"
            );
            pclose(popen('powershell.exe -NoProfile -NonInteractive -WindowStyle Hidden -File "' . $launcherPs1 . '"', 'r'));
        } else {
            $cmd = 'powershell.exe -NoProfile -File ' . escapeshellarg($startScript)
                . ' > ' . escapeshellarg($logPath) . ' 2>&1';
            exec($cmd . ' &');
        }
    }
}
