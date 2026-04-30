<?php

namespace App\Services;

use App\Models\MlResult;
use App\Models\QolSurvey;
use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class MlService
{
    protected string $preprocessUrl;
    protected string $inferenceUrl;
    protected int $timeout;
    protected int $coldStartTimeout;
    protected ?bool $preprocessAvailable = null;
    protected ?bool $inferenceAvailable  = null;
    protected ?string $localPythonExecutable;
    protected string $localPythonRunner;

    public function __construct()
    {
        $base               = config('services.python.base_url', 'http://127.0.0.1');
        $preprocessPort     = (int) config('services.python.preprocess_port', 5001);
        $inferencePort      = (int) config('services.python.inference_port', 5002);
        $this->timeout      = (int) config('services.python.timeout', 120);
        $this->coldStartTimeout = (int) config('services.python.cold_start_timeout', 120);

        $this->preprocessUrl = $base . ':' . $preprocessPort;
        $this->inferenceUrl  = $base . ':' . $inferencePort;
        $this->localPythonExecutable = $this->resolveLocalPythonExecutable();
        $this->localPythonRunner = base_path('python/services/local_ml_runner.py');
    }

    /**
     * Run the full pipeline for a single senior citizen.
     * Uses combined local mode (1 subprocess instead of 2) when Flask is unavailable.
     */
    public function runPipeline(SeniorCitizen $senior, QolSurvey $survey): MlResult
    {
        $raw = $this->buildRawPayload($senior, $survey);

        if ($this->isPreprocessAvailable()) {
            // HTTP mode: two separate service calls
            $preprocessed = $this->callPreprocess($raw);
            $inferResult  = $this->callInfer($preprocessed);
        } else {
            // Local mode: combined preprocess+infer in ONE subprocess (no double cold-start)
            $inferResult  = $this->localCombinedOrFallback($raw);
            $preprocessed = [];
        }

        return $this->persistResults($senior, $survey, $preprocessed, $inferResult);
    }

    /**
     * Run the pipeline for a batch of seniors in one Python subprocess.
     * Eliminates per-senior subprocess cold-start overhead for batch runs.
     *
     * @param array<array{senior: SeniorCitizen, survey: QolSurvey}> $items
     * @return array<array{success: bool, result: MlResult|null, error: string|null}>
     */
    public function runBatchPipeline(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $payloads = array_map(
            fn($item) => $this->buildRawPayload($item['senior'], $item['survey']),
            $items
        );

        // Choose batch strategy based on service availability
        if ($this->isPreprocessAvailable() && $this->isInferenceAvailable()) {
            $rawResults = $this->callBatchHttp($payloads);
        } else {
            $rawResults = $this->callBatchLocal($payloads);
        }

        $output = [];
        foreach ($items as $i => $item) {
            $entry = $rawResults[$i] ?? ['success' => false, 'error' => 'No result returned'];

            if (!($entry['success'] ?? false)) {
                $output[] = [
                    'success' => false,
                    'result'  => null,
                    'error'   => $entry['error'] ?? 'Unknown error',
                ];
                continue;
            }

            try {
                $inferResult = $entry['data'] ?? $entry;
                $result = $this->persistResults($item['senior'], $item['survey'], [], $inferResult);
                $output[] = ['success' => true, 'result' => $result, 'error' => null];
            } catch (\Exception $e) {
                $output[] = ['success' => false, 'result' => null, 'error' => $e->getMessage()];
            }
        }

        return $output;
    }

    /**
     * Preprocess only (no inference) — useful for batch jobs.
     */
    public function preprocess(array $raw): array
    {
        return $this->callPreprocess($raw);
    }

    /**
     * Check if Python services are reachable, and whether local fallbacks are available.
     */
    public function healthCheck(): array
    {
        $results = [];
        foreach ([
            'preprocessor' => $this->preprocessUrl . '/health',
            'inference'    => $this->inferenceUrl  . '/health',
        ] as $name => $url) {
            try {
                $resp = Http::timeout(3)->connectTimeout(2)->get($url);
                $results[$name] = $resp->successful() ? 'ok' : 'error';
            } catch (\Exception) {
                $results[$name] = 'unreachable';
            }
        }

        $results['local_runner'] = $this->canUseLocalPython() ? 'available' : 'unavailable';
        $results['mode'] = ($results['preprocessor'] === 'ok' && $results['inference'] === 'ok')
            ? 'http'
            : ($results['local_runner'] === 'available' ? 'local_python' : 'php_fallback');

        return $results;
    }

    /**
     * Start Python HTTP services as background processes.
     */
    public function startServices(): bool
    {
        $startScript = base_path('python/start_services.ps1');
        if (!is_file($startScript)) {
            return false;
        }

        try {
            $process = new Process(['powershell.exe', '-NoProfile', '-File', $startScript], base_path());
            $process->setTimeout(20);
            $process->run();
        } catch (\Throwable) {
            // best-effort
        }

        foreach ([$this->preprocessUrl . '/health', $this->inferenceUrl . '/health'] as $url) {
            try {
                $resp = Http::timeout(4)->connectTimeout(3)->get($url);
                if (!$resp->successful()) return false;
            } catch (\Exception) {
                return false;
            }
        }
        return true;
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private function buildRawPayload(SeniorCitizen $senior, QolSurvey $survey): array
    {
        return [
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
            'dental_concern'          => $senior->dental_concern ?? [],
            'optical_concern'         => $senior->optical_concern ?? [],
            'hearing_concern'         => $senior->hearing_concern ?? [],
            'social_emotional_concern'=> $senior->social_emotional_concern ?? [],
            'healthcare_difficulty'   => $senior->healthcare_difficulty ?? [],
            'has_medical_checkup'     => $senior->has_medical_checkup && $senior->checkup_schedule !== 'No Follow-up',
            'qol_responses'           => $survey->toFeatureArray(),
        ];
    }

    /**
     * Combined local mode: one subprocess for preprocess + infer.
     */
    private function localCombinedOrFallback(array $raw): array
    {
        $result = $this->runLocalPythonStage('combined', $raw);

        if ($result !== null) {
            return $result;
        }

        // Full PHP fallback
        $preprocessed = $this->fallbackPreprocess($raw);
        return $this->fallbackInfer($preprocessed);
    }

    /**
     * HTTP batch: call /batch_preprocess then /batch_infer (2 HTTP calls for N seniors).
     */
    private function callBatchHttp(array $payloads): array
    {
        try {
            $preResp = Http::connectTimeout(5)
                ->timeout($this->timeout)
                ->post($this->preprocessUrl . '/batch_preprocess', $payloads);

            if ($preResp->failed()) {
                throw new \RuntimeException('Batch preprocess HTTP error: ' . $preResp->status());
            }

            $preprocessedList = $preResp->json('results') ?? [];

            $infResp = Http::connectTimeout(5)
                ->timeout($this->timeout)
                ->post($this->inferenceUrl . '/batch_infer', $preprocessedList);

            if ($infResp->failed()) {
                throw new \RuntimeException('Batch infer HTTP error: ' . $infResp->status());
            }

            $inferResults = $infResp->json('results') ?? [];

            return array_map(
                fn($r) => ['success' => true, 'data' => $r],
                $inferResults
            );
        } catch (\Exception $e) {
            Log::warning('Batch HTTP pipeline failed, falling back to local batch', ['error' => $e->getMessage()]);
            return $this->callBatchLocal($payloads);
        }
    }

    /**
     * Local batch: one Python subprocess processes all seniors, models loaded once.
     * Falls back to sequential combined calls if the batch subprocess fails.
     */
    private function callBatchLocal(array $payloads): array
    {
        if (!$this->canUseLocalPython()) {
            return $this->batchFallback($payloads);
        }

        try {
            $batchEnv = $this->pythonEnvironment();
            $batchEnv['OSCA_BATCH_MODE'] = '1'; // skips per-senior UMAP in preprocess + infer

            $process = new Process(
                [$this->localPythonExecutable, $this->localPythonRunner, 'batch'],
                base_path(),
                $batchEnv
            );

            $process->setInput(json_encode($payloads, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            // Cold-start once + ~2s per senior for computation
            $process->setTimeout(max($this->timeout, $this->coldStartTimeout) + count($payloads) * 2);
            $process->mustRun();

            $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                throw new \RuntimeException('Batch Python runner returned non-array.');
            }

            // If more than half the items failed, assume a systemic subprocess issue
            // and fall back to sequential combined calls (which spawn fresh processes).
            $failCount = count(array_filter($decoded, fn($r) => !($r['success'] ?? false)));
            if ($failCount > count($decoded) / 2) {
                Log::warning('Batch Python: majority of items failed, switching to sequential combined mode', [
                    'failed' => $failCount,
                    'total'  => count($decoded),
                    'sample_error' => collect($decoded)->first(fn($r) => !($r['success'] ?? false))['error'] ?? '',
                ]);
                return $this->callSequentialCombined($payloads);
            }

            return $decoded;
        } catch (ProcessFailedException $e) {
            Log::warning('Local Python batch failed, switching to sequential combined mode', [
                'error' => trim($e->getProcess()->getErrorOutput()) ?: $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Local Python batch failed, switching to sequential combined mode', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->callSequentialCombined($payloads);
    }

    /**
     * Sequential fallback: run combined mode per senior (1 subprocess each).
     * Slower than true batch but avoids repeated-UMAP-in-one-process issues.
     */
    private function callSequentialCombined(array $payloads): array
    {
        return array_map(function ($raw) {
            try {
                $result = $this->runLocalPythonStage('combined', $raw);
                if ($result !== null) {
                    return ['success' => true, 'data' => $result];
                }
            } catch (\Throwable $e) {
                Log::warning('Sequential combined failed for one senior', ['error' => $e->getMessage()]);
            }

            // Last resort: PHP heuristic fallback
            $preprocessed = $this->fallbackPreprocess($raw);
            return ['success' => true, 'data' => $this->fallbackInfer($preprocessed)];
        }, $payloads);
    }

    /**
     * When even local Python fails: PHP-heuristic fallback for every item.
     */
    private function batchFallback(array $payloads): array
    {
        return array_map(function ($raw) {
            $preprocessed = $this->fallbackPreprocess($raw);
            return ['success' => true, 'data' => $this->fallbackInfer($preprocessed)];
        }, $payloads);
    }

    private function callPreprocess(array $raw): array
    {
        if (!$this->isPreprocessAvailable()) {
            return $this->localPreprocessOrFallback($raw);
        }

        try {
            $response = Http::connectTimeout(5)
                ->timeout($this->timeout)
                ->post($this->preprocessUrl . '/preprocess', $raw);

            if ($response->failed()) {
                $this->preprocessAvailable = false;
                Log::error('Preprocess service error', ['body' => $response->body()]);
                return $this->localPreprocessOrFallback($raw);
            }

            return $response->json();
        } catch (ConnectionException $e) {
            if (str_contains(strtolower($e->getMessage()), 'timed out')) {
                try {
                    $response = Http::connectTimeout(5)
                        ->timeout(max($this->timeout, $this->coldStartTimeout))
                        ->post($this->preprocessUrl . '/preprocess', $raw);

                    if ($response->successful()) {
                        $this->preprocessAvailable = true;
                        return $response->json();
                    }
                } catch (\Exception) {
                }
            }

            $this->preprocessAvailable = false;
            Log::warning('Preprocess service unreachable, using fallback', ['error' => $e->getMessage()]);
            return $this->localPreprocessOrFallback($raw);
        } catch (\Exception $e) {
            $this->preprocessAvailable = false;
            Log::warning('Preprocess service error, using fallback', ['error' => $e->getMessage()]);
            return $this->localPreprocessOrFallback($raw);
        }
    }

    private function callInfer(array $preprocessed): array
    {
        if (!$this->isInferenceAvailable()) {
            return $this->localInferOrFallback($preprocessed);
        }

        try {
            $response = Http::connectTimeout(5)
                ->timeout($this->timeout)
                ->post($this->inferenceUrl . '/infer', $preprocessed);

            if ($response->failed()) {
                $this->inferenceAvailable = false;
                Log::error('Inference service error', ['body' => $response->body()]);
                return $this->localInferOrFallback($preprocessed);
            }

            return $response->json();
        } catch (ConnectionException $e) {
            if (str_contains(strtolower($e->getMessage()), 'timed out')) {
                try {
                    $response = Http::connectTimeout(5)
                        ->timeout(max($this->timeout, $this->coldStartTimeout))
                        ->post($this->inferenceUrl . '/infer', $preprocessed);

                    if ($response->successful()) {
                        $this->inferenceAvailable = true;
                        return $response->json();
                    }
                } catch (\Exception) {
                }
            }

            $this->inferenceAvailable = false;
            Log::warning('Inference service unreachable, using fallback', ['error' => $e->getMessage()]);
            return $this->localInferOrFallback($preprocessed);
        } catch (\Exception $e) {
            $this->inferenceAvailable = false;
            Log::warning('Inference service error, using fallback', ['error' => $e->getMessage()]);
            return $this->localInferOrFallback($preprocessed);
        }
    }

    private function isPreprocessAvailable(): bool
    {
        if ($this->preprocessAvailable !== null) {
            return $this->preprocessAvailable;
        }

        return $this->preprocessAvailable = $this->checkHealth($this->preprocessUrl . '/health', 'Preprocess');
    }

    private function isInferenceAvailable(): bool
    {
        if ($this->inferenceAvailable !== null) {
            return $this->inferenceAvailable;
        }

        return $this->inferenceAvailable = $this->checkHealth($this->inferenceUrl . '/health', 'Inference');
    }

    private function checkHealth(string $url, string $serviceName): bool
    {
        try {
            $resp = Http::timeout(2)->connectTimeout(2)->get($url);
            if ($resp->successful()) {
                return true;
            }

            Log::warning("{$serviceName} health check failed; using fallback", ['status' => $resp->status()]);
            return false;
        } catch (\Exception $e) {
            Log::warning("{$serviceName} unreachable; using fallback", ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function localPreprocessOrFallback(array $raw): array
    {
        return $this->runLocalPythonStage('preprocess', $raw) ?? $this->fallbackPreprocess($raw);
    }

    private function localInferOrFallback(array $preprocessed): array
    {
        return $this->runLocalPythonStage('infer', $preprocessed) ?? $this->fallbackInfer($preprocessed);
    }

    private function runLocalPythonStage(string $stage, array $payload): ?array
    {
        if (!$this->canUseLocalPython()) {
            return null;
        }

        try {
            $process = new Process(
                [$this->localPythonExecutable, $this->localPythonRunner, $stage],
                base_path(),
                $this->pythonEnvironment()
            );

            $process->setInput(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $process->setTimeout(max($this->timeout, $this->coldStartTimeout));
            $process->mustRun();

            $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                throw new \RuntimeException('Local Python stage returned a non-array payload.');
            }

            if ($stage !== 'batch') {
                $decoded['warnings'] = array_values(array_unique(array_merge(
                    $decoded['warnings'] ?? [],
                    ['Served by local Python runner because HTTP ML services were unavailable.']
                )));
            }

            return $decoded;
        } catch (ProcessFailedException $e) {
            Log::warning('Local Python ML stage failed', [
                'stage' => $stage,
                'error' => trim($e->getProcess()->getErrorOutput()) ?: $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Local Python ML stage failed', [
                'stage' => $stage,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function canUseLocalPython(): bool
    {
        return $this->localPythonExecutable !== null && is_file($this->localPythonRunner);
    }

    private function resolveLocalPythonExecutable(): ?string
    {
        $configured = env('PYTHON_EXECUTABLE');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        foreach ([
            base_path('python/venv/Scripts/python.exe'),
            base_path('python/venv/bin/python'),
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function pythonEnvironment(): array
    {
        // Inherit the full parent process environment so Windows DLLs, Winsock,
        // TEMP dirs, and PATH are all available inside the subprocess.
        // Passing a sparse array would strip those variables and cause WinError 10106
        // (Winsock provider init failure) and numba/asyncio corruption.
        $env = getenv() ?: [];

        $modelsPath = env('ML_MODELS_PATH');
        if (is_string($modelsPath) && trim($modelsPath) !== '') {
            $env['ML_MODELS_PATH'] = trim($modelsPath);
        }

        $enableNotebookOverrides = env('ENABLE_NOTEBOOK_OVERRIDES');
        if ($enableNotebookOverrides !== null) {
            $env['ENABLE_NOTEBOOK_OVERRIDES'] = (string) $enableNotebookOverrides;
        }

        // Force numba to use the workqueue threading layer (pure-Python threads)
        // instead of the default omp/tbb layer, which uses Windows socket-based IPC.
        // This prevents WinError 10106 and asyncio state corruption when UMAP's
        // reducer.transform() is called multiple times inside one subprocess.
        $env['NUMBA_THREADING_LAYER'] = $env['NUMBA_THREADING_LAYER'] ?? 'workqueue';
        $env['NUMBA_NUM_THREADS']     = $env['NUMBA_NUM_THREADS']     ?? '1';
        $env['OMP_NUM_THREADS']       = $env['OMP_NUM_THREADS']       ?? '1';

        return $env;
    }

    private function persistResults(
        SeniorCitizen $senior,
        QolSurvey $survey,
        array $preprocessed,
        array $inferResult
    ): MlResult {
        $cluster      = $inferResult['cluster']      ?? [];
        $scores       = $inferResult['risk_scores']  ?? [];
        $levels       = $inferResult['risk_levels']  ?? [];
        $domainRisks  = $inferResult['domain_risks'] ?? [];
        $whoScores    = $inferResult['who_scores']   ?? [];

        // section_scores come from preprocessed in HTTP mode, or forwarded in inferResult in combined/batch mode
        $sectionScores = $preprocessed['section_scores'] ?? $inferResult['section_scores'] ?? null;

        /** @var MlResult $mlResult */
        $mlResult = MlResult::updateOrCreate(
            ['senior_citizen_id' => $senior->id, 'qol_survey_id' => $survey->id],
            [
                'model_version'      => 'v1',
                'cluster_id'         => $cluster['raw_id']   ?? null,
                'cluster_named_id'   => $cluster['named_id'] ?? null,
                'cluster_name'       => $cluster['name']     ?? null,
                'ic_risk'            => $scores['ic_risk']         ?? null,
                'env_risk'           => $scores['env_risk']        ?? null,
                'func_risk'          => $scores['func_risk']       ?? null,
                'composite_risk'     => $scores['composite_risk']  ?? null,
                'wellbeing_score'    => $scores['wellbeing_score'] ?? null,
                'ic_risk_level'      => $levels['ic']      ?? null,
                'env_risk_level'     => $levels['env']     ?? null,
                'func_risk_level'    => $levels['func']    ?? null,
                'overall_risk_level' => $levels['overall'] ?? null,
                // Rule-based domain risks
                'risk_medical'       => $domainRisks['risk_medical']    ?? null,
                'risk_financial'     => $domainRisks['risk_financial']  ?? null,
                'risk_social'        => $domainRisks['risk_social']     ?? null,
                'risk_functional'    => $domainRisks['risk_functional'] ?? null,
                'risk_housing'       => $domainRisks['risk_housing']    ?? null,
                'risk_hc_access'     => $domainRisks['risk_hc_access']  ?? null,
                'risk_sensory'       => $domainRisks['risk_sensory']    ?? null,
                'rule_composite'     => $domainRisks['rule_composite']  ?? null,
                // WHO domain scores
                'ic_score'           => $whoScores['ic_score']   ?? null,
                'env_score'          => $whoScores['env_score']  ?? null,
                'func_score'         => $whoScores['func_score'] ?? null,
                'qol_score'          => $whoScores['qol_score']  ?? null,
                'section_scores'     => $sectionScores,
                'raw_output'         => $inferResult,
                'processed_at'       => now(),
            ]
        );

        // Clear old recommendations and insert fresh ones
        $mlResult->recommendations()->delete();
        $recs = [];
        foreach ($inferResult['recommendations'] ?? [] as $rec) {
            $recs[] = [
                'ml_result_id'      => $mlResult->id,
                'senior_citizen_id' => $senior->id,
                'priority'          => $rec['priority'],
                'type'              => $rec['type'],
                'domain'            => $rec['domain']      ?? null,
                'category'          => $rec['category']    ?? null,
                'action'            => $rec['action'],
                'urgency'           => $rec['urgency']     ?? null,
                'risk_level'        => $rec['risk_level']  ?? null,
                'created_at'        => now(),
                'updated_at'        => now(),
            ];
        }
        if ($recs) {
            Recommendation::insert($recs); // single INSERT for all rows
        }

        return $mlResult->fresh(['recommendations']);
    }

    private function fallbackPreprocess(array $raw): array
    {
        $age      = (int) ($raw['age'] ?? 70);
        $wellbeing = 0.5;

        return [
            'status'           => 'success_fallback',
            'encoded_features' => ['age' => $age],
            'scaled_features'  => array_fill(0, 35, 0.0),
            'reduced_features' => array_fill(0, 10, 0.0),
            'section_scores'   => [
                'sec1_age_risk'        => max(0, ($age - 60) / 40),
                'sec2_family_support'  => 0.5,
                'sec3_hr_score'        => 0.5,
                'sec4_dependency_risk' => 0.3,
                'sec5_eco_stability'   => 0.5,
                'sec6_health_score'    => 0.5,
                'overall_wellbeing'    => $wellbeing,
            ],
            'who_domain_scores' => [
                'ic_score' => 3.0, 'env_score' => 3.0,
                'func_score' => 3.0, 'qol_score' => 3.0,
            ],
        ];
    }

    private function fallbackInfer(array $preprocessed): array
    {
        $ss        = $preprocessed['section_scores'] ?? [];
        $who       = $preprocessed['who_domain_scores'] ?? [];
        $wellbeing = (float) ($ss['overall_wellbeing'] ?? 0.5);
        $composite = round(1 - $wellbeing, 4);

        // Thresholds mirror osca5.ipynb: CRITICAL>=0.65, HIGH>=0.45, MODERATE>=0.25, LOW<0.25
        $level     = $composite >= 0.65 ? 'CRITICAL' : ($composite >= 0.45 ? 'HIGH' : ($composite >= 0.25 ? 'MODERATE' : 'LOW'));
        $clusterId = $composite >= 0.45 ? 3 : ($composite >= 0.25 ? 2 : 1);

        // Derive domain risks from available section scores (same logic as _compute_rule_based_risk)
        $ageRisk      = (float) ($ss['sec1_age_risk']        ?? 0.5);
        $healthScore  = (float) ($ss['sec6_health_score']    ?? 0.5);
        $ecoStability = (float) ($ss['sec5_eco_stability']   ?? 0.5);
        $famSupport   = (float) ($ss['sec2_family_support']  ?? 0.5);
        $depRisk      = (float) ($ss['sec4_dependency_risk'] ?? 0.3);
        $hrScore      = (float) ($ss['sec3_hr_score']        ?? 0.5);

        $domainRisks = [
            'risk_medical'    => round(min($healthScore, 1.0), 4),
            'risk_financial'  => round(min(max(1.0 - $ecoStability, 0.0), 1.0), 4),
            'risk_social'     => round(min(max(1.0 - $famSupport, 0.0), 1.0), 4),
            'risk_functional' => round(min($ageRisk * 0.5 + (1.0 - $hrScore) * 0.5, 1.0), 4),
            'risk_housing'    => round(min((float) ($ss['sec4_household_risk'] ?? 0.0), 1.0), 4),
            'risk_hc_access'  => round(min($healthScore * 0.5 + $ageRisk * 0.5, 1.0), 4),
            'risk_sensory'    => round(min($healthScore * 0.6, 1.0), 4),
            'rule_composite'  => $composite,
        ];

        return [
            'status'  => 'success_fallback',
            'cluster' => [
                'raw_id'  => $clusterId - 1,
                'named_id' => $clusterId,
                'name'    => ['', 'High Functioning', 'Moderate / Mixed Needs', 'Low Functioning / Multi-Domain Risk'][$clusterId],
                'ic' => 'Unknown', 'env' => 'Unknown', 'func' => 'Unknown',
                'description' => 'Heuristic assignment — ML service unavailable.',
            ],
            'risk_scores' => [
                'ic_risk'         => $composite,
                'env_risk'        => $composite,
                'func_risk'       => $composite,
                'composite_risk'  => $composite,
                'wellbeing_score' => $wellbeing,
            ],
            'risk_levels' => [
                'ic' => strtolower($level), 'env' => strtolower($level),
                'func' => strtolower($level), 'overall' => $level,
            ],
            'domain_risks' => $domainRisks,
            'who_scores'   => [
                'ic_score'   => (float) ($who['ic_score']   ?? 3.0),
                'env_score'  => (float) ($who['env_score']  ?? 3.0),
                'func_score' => (float) ($who['func_score'] ?? 3.0),
                'qol_score'  => (float) ($who['qol_score']  ?? 3.0),
            ],
            'section_scores' => $ss,
            'recommendations' => [
                [
                    'priority' => 1, 'type' => 'general', 'domain' => 'general', 'category' => 'general',
                    'action'   => 'ML service unavailable. Please re-run analysis when the service is restored.',
                    'urgency'  => 'planned', 'risk_level' => strtolower($level),
                ],
            ],
            'warnings' => ['Python ML services are currently unreachable. Fallback heuristics used.'],
        ];
    }
}
