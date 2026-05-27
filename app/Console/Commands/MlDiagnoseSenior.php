<?php

namespace App\Console\Commands;

use App\Models\SeniorCitizen;
use App\Services\MlService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class MlDiagnoseSenior extends Command
{
    protected $signature   = 'ml:diagnose-senior {seniorId : The ID of the senior citizen to diagnose}';
    protected $description = 'Compare live-model scores vs notebook CSV for a single senior (diagnostic tool).';

    public function handle(MlService $ml): int
    {
        $seniorId = (int) $this->argument('seniorId');
        $senior   = SeniorCitizen::find($seniorId);

        if (!$senior) {
            $this->error("Senior #{$seniorId} not found.");
            return self::FAILURE;
        }

        $survey = $senior->latestQolSurvey;
        if (!$survey) {
            $this->error("Senior #{$seniorId} has no QoL survey.");
            return self::FAILURE;
        }

        $this->info("=== ml:diagnose-senior ===");
        $this->info("Senior  : {$senior->first_name} {$senior->last_name}  (ID {$senior->id})");
        $this->info("Barangay: {$senior->barangay}  |  Age: {$senior->age}");
        $this->line('');

        // 1. Run live model (forced ENABLE_NOTEBOOK_OVERRIDES=false)
        $liveResult = $this->runLiveModel($ml, $senior, $survey);
        if ($liveResult === null) {
            $this->error("Live model subprocess failed. Check storage/app/ml_err_*.txt for details.");
            return self::FAILURE;
        }

        // 2. Look up notebook CSV
        $csvRow = $this->findCsvRow($senior);

        // 3. Print comparison table
        $this->printComparison($liveResult, $csvRow);

        return self::SUCCESS;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function runLiveModel(MlService $ml, $senior, $survey): ?array
    {
        $python = $this->resolvePython();
        $runner = base_path('python/services/local_ml_runner.py');

        if (!$python || !is_file($runner)) {
            $this->error("Python executable or local_ml_runner.py not found.");
            return null;
        }

        $payload = $this->buildPayload($senior, $survey);
        $input   = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Inherit full environment so DLLs / PATH are available, then force the flag.
        $env = getenv() ?: [];
        $env['ENABLE_NOTEBOOK_OVERRIDES'] = '0';
        $env['NUMBA_THREADING_LAYER']     = 'workqueue';
        $env['NUMBA_NUM_THREADS']         = '1';
        $env['OMP_NUM_THREADS']           = '1';

        $outFile = storage_path('app/ml_diag_out_' . uniqid('', true) . '.json');
        $errFile = storage_path('app/ml_diag_err_' . uniqid('', true) . '.txt');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ];

        $proc = proc_open([$python, $runner, 'combined'], $descriptors, $pipes, base_path(), $env);

        if (!is_resource($proc)) {
            return null;
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        $start = time();
        while (proc_get_status($proc)['running']) {
            if (time() - $start > 120) {
                proc_terminate($proc);
                $this->error("Python subprocess timed out.");
                return null;
            }
            usleep(200_000);
        }

        $exitCode = proc_close($proc);
        $stderr   = is_file($errFile) ? trim(file_get_contents($errFile)) : '';
        $output   = is_file($outFile) ? file_get_contents($outFile) : '';

        @unlink($outFile);
        @unlink($errFile);

        if ($exitCode !== 0 || !$output) {
            if ($stderr) $this->line("<comment>Python stderr:</comment> {$stderr}");
            return null;
        }

        $data = json_decode($output, true);
        return is_array($data) ? $data : null;
    }

    private function findCsvRow($senior): ?array
    {
        $csvPath = base_path('python/models/predictions/senior_predictions.csv');
        if (!is_file($csvPath)) {
            return null;
        }

        $handle = fopen($csvPath, 'r');
        if (!$handle) return null;

        $headers = fgetcsv($handle);
        $firstNameIdx = array_search('first_name', $headers);
        $lastNameIdx  = array_search('last_name',  $headers);

        $targetFirst = strtolower(trim($senior->first_name));
        $targetLast  = strtolower(trim($senior->last_name));

        while (($row = fgetcsv($handle)) !== false) {
            if (strtolower(trim($row[$firstNameIdx] ?? '')) === $targetFirst
                && strtolower(trim($row[$lastNameIdx] ?? ''))  === $targetLast) {
                fclose($handle);
                return array_combine($headers, $row);
            }
        }

        fclose($handle);
        return null;
    }

    private function printComparison(array $live, ?array $csv): void
    {
        $riskScores = $live['risk_scores'] ?? [];
        $riskLevels = $live['risk_levels'] ?? [];
        $cluster    = $live['cluster']     ?? [];

        $liveIcRisk   = number_format((float)($riskScores['ic_risk']       ?? 0), 4);
        $liveEnvRisk  = number_format((float)($riskScores['env_risk']      ?? 0), 4);
        $liveFuncRisk = number_format((float)($riskScores['func_risk']     ?? 0), 4);
        $liveComp     = number_format((float)($riskScores['composite_risk'] ?? 0), 4);
        $liveLevel    = $riskLevels['overall'] ?? '—';
        $liveCluster  = $cluster['named_id']   ?? '—';

        $csvIcRisk    = $csv ? number_format((float)($csv['ml_ic_risk']    ?? 0), 4) : '—';
        $csvEnvRisk   = $csv ? number_format((float)($csv['ml_env_risk']   ?? 0), 4) : '—';
        $csvFuncRisk  = $csv ? number_format((float)($csv['ml_func_risk']  ?? 0), 4) : '—';
        $csvComp      = $csv ? number_format((float)($csv['composite_risk'] ?? 0), 4) : '—';
        $csvLevel     = $csv ? strtoupper($csv['risk_level'] ?? '—') : '—';
        $csvCluster   = $csv ? ($csv['cluster_id'] ?? '—') : '—';

        $delta = fn($l, $c) => ($c === '—') ? '—' : number_format((float)$l - (float)$c, 4);

        $this->info("=== Risk Score Comparison ===");
        $this->table(
            ['Metric', 'Live Model (ENABLE_NB=false)', 'Notebook CSV', 'Δ (live − csv)'],
            [
                ['IC risk',        $liveIcRisk,   $csvIcRisk,   $delta($liveIcRisk,   $csvIcRisk)],
                ['ENV risk',       $liveEnvRisk,  $csvEnvRisk,  $delta($liveEnvRisk,  $csvEnvRisk)],
                ['FUNC risk',      $liveFuncRisk, $csvFuncRisk, $delta($liveFuncRisk, $csvFuncRisk)],
                ['Composite risk', $liveComp,     $csvComp,     $delta($liveComp,     $csvComp)],
                ['Risk level',     $liveLevel,    $csvLevel,    $liveLevel !== $csvLevel ? '⚠ MISMATCH' : '✓ match'],
                ['Cluster',        "C{$liveCluster}", "C{$csvCluster}", $liveCluster != $csvCluster ? '⚠ MISMATCH' : '✓ match'],
            ]
        );

        if ($csv === null) {
            $this->warn("Senior not found in senior_predictions.csv — no notebook baseline available.");
        }
    }

    private function buildPayload($senior, $survey): array
    {
        return [
            'senior_id'               => $senior->id,
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
            'income_source'           => $senior->income_source       ?? [],
            'real_assets'             => $senior->real_assets          ?? [],
            'movable_assets'          => $senior->movable_assets       ?? [],
            'living_with'             => $senior->living_with          ?? [],
            'household_condition'     => $senior->household_condition  ?? [],
            'community_service'       => $senior->community_service    ?? [],
            'specialization'          => $senior->specialization       ?? [],
            'medical_concern'         => $senior->medical_concern      ?? [],
            'dental_concern'          => $senior->dental_concern       ?? [],
            'optical_concern'         => $senior->optical_concern      ?? [],
            'hearing_concern'         => $senior->hearing_concern      ?? [],
            'social_emotional_concern'=> $senior->social_emotional_concern ?? [],
            'healthcare_difficulty'   => $senior->healthcare_difficulty ?? [],
            'has_medical_checkup'     => $senior->has_medical_checkup
                                         && $senior->checkup_schedule !== 'No Follow-up',
            'qol_responses'           => $survey->toFeatureArray(),
        ];
    }

    private function resolvePython(): ?string
    {
        foreach (['python3', 'python', 'py'] as $candidate) {
            $proc = new Process([$candidate, '--version']);
            try {
                $proc->run();
                if ($proc->isSuccessful()) return $candidate;
            } catch (\Throwable) {}
        }

        // Windows venv / conda paths
        $winCandidates = [
            base_path('venv/Scripts/python.exe'),
            base_path('.venv/Scripts/python.exe'),
            base_path('python/venv/Scripts/python.exe'),
        ];
        foreach ($winCandidates as $p) {
            if (is_file($p)) return $p;
        }

        return null;
    }
}
