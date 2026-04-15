<?php
require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

$raw = [
    'first_name' => 'A',
    'last_name' => 'B',
    'barangay' => 'X',
    'age' => 70,
];

$start = microtime(true);
try {
    $resp = Http::timeout(30)->post('http://127.0.0.1:5001/preprocess', $raw);
    $elapsed = round(microtime(true) - $start, 3);
    echo "status_code={$resp->status()} elapsed={$elapsed}s\n";
    echo $resp->body(), "\n";
} catch (Throwable $e) {
    $elapsed = round(microtime(true) - $start, 3);
    echo "EXCEPTION elapsed={$elapsed}s\n";
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
