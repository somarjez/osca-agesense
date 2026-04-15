<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SeniorCitizen;
use App\Models\QolSurvey;
use App\Models\MlResult;

$latestMlIds = MlResult::selectRaw('MAX(id) as id')->groupBy('senior_citizen_id')->pluck('id');
$latestSurveyIds = QolSurvey::selectRaw('MAX(id) as id')->groupBy('senior_citizen_id')->pluck('id');

$out = [
  'active_seniors' => SeniorCitizen::active()->count(),
  'processed_surveys_total' => QolSurvey::where('status', 'processed')->count(),
  'latest_processed_surveys_distinct_seniors' => QolSurvey::whereIn('id', $latestSurveyIds)->where('status', 'processed')->count(),
  'latest_ml_results_distinct_seniors' => MlResult::whereIn('id', $latestMlIds)->count(),
  'cluster_counts_latest_ml' => MlResult::whereIn('id', $latestMlIds)->selectRaw('cluster_named_id, cluster_name, COUNT(*) as count')->groupBy('cluster_named_id', 'cluster_name')->orderBy('cluster_named_id')->get()->toArray(),
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
