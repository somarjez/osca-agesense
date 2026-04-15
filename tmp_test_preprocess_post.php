<?php
require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

$senior = App\Models\SeniorCitizen::active()->with('latestQolSurvey')->orderBy('id')->first();
$survey = $senior?->latestQolSurvey;
if (!$senior || !$survey) {
    echo "No senior/survey\n";
    exit(1);
}

$raw = [
    'first_name'              => $senior->first_name,
    'last_name'               => $senior->last_name,
    'barangay'                => $senior->barangay,
    'age'                     => $senior->age,
    'gender'                  => $senior->gender,
    'marital_status'          => $senior->marital_status,
    'educational_attainment'  => $senior->educational_attainment,
    'monthly_income_range'    => $senior->monthly_income_range,
    'num_children'            => $senior->num_children,
    'num_working_children'    => $senior->num_working_children,
    'household_size'          => $senior->household_size,
    'child_financial_support' => $senior->child_financial_support,
    'spouse_working'          => $senior->spouse_working,
    'income_source'           => $senior->income_source ?? [],
    'real_assets'             => $senior->real_assets ?? [],
    'movable_assets'          => $senior->movable_assets ?? [],
    'living_with'             => $senior->living_with ?? [],
    'household_condition'     => $senior->household_condition ?? [],
    'community_service'       => $senior->community_service ?? [],
    'specialization'          => $senior->specialization ?? [],
    'medical_concern'         => $senior->medical_concern ?? [],
    'dental_concern'          => $senior->dental_concern,
    'optical_concern'         => $senior->optical_concern,
    'hearing_concern'         => $senior->hearing_concern,
    'social_emotional_concern'=> $senior->social_emotional_concern ?? [],
    'healthcare_difficulty'   => $senior->healthcare_difficulty,
    'has_medical_checkup'     => $senior->has_medical_checkup,
    'qol_responses'           => $survey->toFeatureArray(),
];

$jsonPayload = json_encode($raw);
echo "payload_bytes=" . strlen((string) $jsonPayload) . "\n";
echo "qol_responses_type=" . gettype($raw['qol_responses']) . "\n";
if (is_array($raw['qol_responses'])) {
    echo "qol_responses_count=" . count($raw['qol_responses']) . "\n";
}

$start = microtime(true);
try {
    $resp = Http::timeout(30)->post('http://127.0.0.1:5001/preprocess', $raw);
    $elapsed = round(microtime(true) - $start, 3);
    echo "status_code={$resp->status()} elapsed={$elapsed}s\n";
    $json = $resp->json();
    echo "response_status=" . ($json['status'] ?? 'null') . "\n";
    echo "response_keys=" . implode(',', array_keys($json ?? [])) . "\n";
} catch (Throwable $e) {
    $elapsed = round(microtime(true) - $start, 3);
    echo "EXCEPTION elapsed={$elapsed}s\n";
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}

$start = microtime(true);
try {
    $resp = Http::withHeaders(['Expect' => ''])
        ->timeout(30)
        ->post('http://127.0.0.1:5001/preprocess', $raw);
    $elapsed = round(microtime(true) - $start, 3);
    echo "NO_EXPECT status_code={$resp->status()} elapsed={$elapsed}s\n";
    $json = $resp->json();
    echo "NO_EXPECT response_status=" . ($json['status'] ?? 'null') . "\n";
} catch (Throwable $e) {
    $elapsed = round(microtime(true) - $start, 3);
    echo "NO_EXPECT EXCEPTION elapsed={$elapsed}s\n";
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
