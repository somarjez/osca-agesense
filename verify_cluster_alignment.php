<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\MlResult;
use App\Models\SeniorCitizen;
use Illuminate\Support\Facades\DB;

$senior = SeniorCitizen::where('first_name', 'Ben')
    ->where('last_name', 'Lubugin')
    ->first();

$ml = $senior?->mlResults()->latest('processed_at')->first();

$latest = MlResult::select('senior_citizen_id', DB::raw('MAX(processed_at) as max_processed_at'))
    ->groupBy('senior_citizen_id');

$clusters = MlResult::joinSub($latest, 'latest', function ($join) {
        $join->on('ml_results.senior_citizen_id', '=', 'latest.senior_citizen_id')
            ->on('ml_results.processed_at', '=', 'latest.max_processed_at');
    })
    ->select('cluster_named_id', DB::raw('COUNT(*) as total'))
    ->groupBy('cluster_named_id')
    ->orderBy('cluster_named_id')
    ->get()
    ->toArray();

echo json_encode([
    'ben' => $ml ? [
        'senior_id' => $senior->id,
        'cluster_named_id' => $ml->cluster_named_id,
        'cluster_name' => $ml->cluster_name,
        'composite_risk' => $ml->composite_risk,
        'section_scores' => $ml->section_scores,
        'raw_output' => $ml->raw_output,
    ] : null,
    'cluster_counts' => $clusters,
], JSON_PRETTY_PRINT);
