<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MlResult;

$rows = MlResult::latest('id')->take(5)->get(['id','senior_citizen_id','cluster_name','overall_risk_level','processed_at','raw_output']);
foreach ($rows as $row) {
  echo json_encode([
    'id' => $row->id,
    'senior_citizen_id' => $row->senior_citizen_id,
    'cluster_name' => $row->cluster_name,
    'overall_risk_level' => $row->overall_risk_level,
    'processed_at' => optional($row->processed_at)->toDateTimeString(),
    'status' => $row->raw_output['status'] ?? null,
    'warnings' => $row->raw_output['warnings'] ?? null,
  ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
