<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Support\Facades\DB;

@set_time_limit(0);
@ini_set('max_execution_time', '0');
DB::disableQueryLog();

$ml = $app->make(MlService::class);
$success = 0;
$failed = 0;
$errors = [];

SeniorCitizen::active()
    ->whereHas('latestQolSurvey', fn ($query) => $query->where('status', 'processed'))
    ->with(['latestQolSurvey'])
    ->orderBy('id')
    ->chunk(20, function ($seniors) use ($ml, &$success, &$failed, &$errors) {
        foreach ($seniors as $senior) {
            try {
                $survey = $senior->latestQolSurvey;
                if (!$survey) {
                    $failed++;
                    continue;
                }

                $ml->runPipeline($senior, $survey);
                $success++;
                echo "Recomputed senior #{$senior->id} {$senior->full_name}\n";
            } catch (Throwable $exception) {
                $failed++;
                if (count($errors) < 10) {
                    $errors[] = "{$senior->id}: {$exception->getMessage()}";
                }
                echo "FAILED senior #{$senior->id} {$senior->full_name}: {$exception->getMessage()}\n";
            }
        }
    });

echo "\nDONE: success={$success}, failed={$failed}\n";
if ($errors) {
    echo "Errors:\n" . implode("\n", $errors) . "\n";
}
