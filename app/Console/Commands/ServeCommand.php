<?php

namespace App\Console\Commands;

use App\Services\MlService;
use Illuminate\Foundation\Console\ServeCommand as BaseServeCommand;

class ServeCommand extends BaseServeCommand
{
    public function handle()
    {
        $this->startMlServices();
        return parent::handle();
    }

    private function startMlServices(): void
    {
        $startScript = base_path('python/start_services.ps1');

        if (!is_file($startScript)) {
            $this->warn('  [ML] Python start script not found — skipping auto-start.');
            return;
        }

        $this->info('  <fg=cyan>[ML]</> Starting Python ML services...');

        try {
            /** @var MlService $ml */
            $ml = $this->laravel->make(MlService::class);
            $started = $ml->startServices();

            if ($started) {
                $this->info('  <fg=green>[ML]</> Preprocessor :5001 and Inference :5002 are online.');
            } else {
                $this->warn('  <fg=yellow>[ML]</> Services did not respond in time — system will use local Python fallback.');
            }
        } catch (\Throwable $e) {
            $this->warn('  <fg=yellow>[ML]</> Could not start ML services: ' . $e->getMessage());
        }
    }
}
