<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MlResult;
use Illuminate\Support\Facades\DB;

$latestIds = MlResult::select(DB::raw('MAX(id) as id'))->groupBy('senior_citizen_id')->pluck('id');
$rows = MlResult::whereIn('id', $latestIds)
    ->selectRaw('cluster_named_id, cluster_name, COUNT(*) as count')
    ->groupBy('cluster_named_id', 'cluster_name')
    ->orderBy('cluster_named_id')
    ->get();

echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
