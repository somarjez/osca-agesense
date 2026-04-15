<?php
require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$ml = $app->make(App\Services\MlService::class);
$senior = App\Models\SeniorCitizen::active()->with('latestQolSurvey')->orderBy('id')->first();

if (!$senior || !$senior->latestQolSurvey) {
    echo "No senior with latestQolSurvey found\n";
    exit(1);
}

$result = $ml->runPipeline($senior, $senior->latestQolSurvey);

echo "senior_id={$senior->id}\n";
echo "cluster_named_id=" . $result->cluster_named_id . "\n";
echo "cluster_name=" . $result->cluster_name . "\n";
$raw = $result->raw_output ?: [];
echo "status=" . ($raw['status'] ?? 'null') . "\n";
echo "warnings=" . json_encode($raw['warnings'] ?? []) . "\n";
