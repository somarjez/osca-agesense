<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Support\Facades\Http;

$ml = $app->make(MlService::class);
$senior = SeniorCitizen::active()->with('latestQolSurvey')->orderBy('id')->first();
$survey = $senior?->latestQolSurvey;

if (!$senior || !$survey) {
    fwrite(STDERR, "No senior with survey found\n");
    exit(1);
}

$ref = new ReflectionClass($ml);
$buildRawPayload = $ref->getMethod('buildRawPayload');
$buildRawPayload->setAccessible(true);
$runLocalStage = $ref->getMethod('runLocalPythonStage');
$runLocalStage->setAccessible(true);

$raw = $buildRawPayload->invoke($ml, $senior, $survey);
$livePre = Http::timeout(120)->post('http://127.0.0.1:5001/preprocess', $raw)->json();
$liveInfer = Http::timeout(120)->post('http://127.0.0.1:5002/infer', $livePre)->json();
$localPre = $runLocalStage->invoke($ml, 'preprocess', $raw);
$localInfer = $runLocalStage->invoke($ml, 'infer', $localPre);

echo json_encode([
    'senior_id' => $senior->id,
    'live' => [
        'pre_status' => $livePre['status'] ?? null,
        'feature_map_count' => is_array($livePre['feature_map'] ?? null) ? count($livePre['feature_map']) : null,
        'cluster_feature_names_count' => is_array($livePre['cluster_feature_names'] ?? null) ? count($livePre['cluster_feature_names']) : null,
        'risk_scores' => $liveInfer['risk_scores'] ?? null,
        'risk_levels' => $liveInfer['risk_levels'] ?? null,
        'warnings' => $liveInfer['warnings'] ?? [],
        'model_dir' => $liveInfer['model_metadata']['model_dir'] ?? null,
    ],
    'local' => [
        'pre_status' => $localPre['status'] ?? null,
        'feature_map_count' => is_array($localPre['feature_map'] ?? null) ? count($localPre['feature_map']) : null,
        'cluster_feature_names_count' => is_array($localPre['cluster_feature_names'] ?? null) ? count($localPre['cluster_feature_names']) : null,
        'risk_scores' => $localInfer['risk_scores'] ?? null,
        'risk_levels' => $localInfer['risk_levels'] ?? null,
        'warnings' => $localInfer['warnings'] ?? [],
        'model_dir' => $localInfer['model_metadata']['model_dir'] ?? null,
    ],
], JSON_PRETTY_PRINT);
