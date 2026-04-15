<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MlResult;

foreach ([1,2,3,178] as $sid) {
  $row = MlResult::where('senior_citizen_id', $sid)->latest('id')->first();
  echo json_encode([
    'senior_id' => $sid,
    'cluster_named_id' => $row?->cluster_named_id,
    'cluster_name' => $row?->cluster_name,
    'composite_risk' => $row?->composite_risk,
    'overall_risk_level' => $row?->overall_risk_level,
    'processed_at' => $row?->processed_at?->toDateTimeString(),
  ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
