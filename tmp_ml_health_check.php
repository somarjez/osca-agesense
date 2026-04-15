<?php
require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$ml = $app->make(App\Services\MlService::class);

$result = $ml->healthCheck();
echo json_encode($result, JSON_PRETTY_PRINT), PHP_EOL;
