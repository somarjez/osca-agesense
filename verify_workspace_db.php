<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MlResult;

foreach ([1,178] as $sid) {
  $row = MlResult::where('senior_citizen_id', $sid)->latest('id')->first();
  echo json_encode([
    'senior_id' => $sid,
    'processed_at' => $row?->processed_at?->toDateTimeString(),
    'composite_risk' => $row?->composite_risk,
    'overall_risk_level' => $row?->overall_risk_level,
  ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
