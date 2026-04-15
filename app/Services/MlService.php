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
    protected ?bool $inferenceAvailable = null;
    protected ?string $localPythonExecutable;
    protected string $localPythonRunner;

    public function __construct()
    {
        $base = config('services.python.base_url', 'http://127.0.0.1');
        $preprocessPort = (int) config('services.python.preprocess_port', 5001);
        $inferencePort  = (int) config('services.python.inference_port', 5002);
        $this->timeout  = (int) config('services.python.timeout', 120);
        $this->coldStartTimeout = (int) config('services.python.cold_start_timeout', 120);

        $this->preprocessUrl = $base . ':' . $preprocessPort;
        $this->inferenceUrl  = $base . ':' . $inferencePort;
        $this->localPythonExecutable = $this->resolveLocalPythonExecutable();
        $this->localPythonRunner = base_path('python/services/local_ml_runner.py');
    }

    /**
     * Run the full pipeline for a senior citizen:
     *   1. Preprocess raw profile + QoL survey
     *   2. Run ML inference (cluster + risk)
     *   3. Persist MlResult + Recommendations
     */
    public function runPipeline(SeniorCitizen $senior, QolSurvey $survey): MlResult
    {
        // ── Step 1: Build raw payload ─────────────────────────────────────────
        $raw = $this->buildRawPayload($senior, $survey);

        // ── Step 2: Preprocess ────────────────────────────────────────────────
        $preprocessed = $this->callPreprocess($raw);

        // ── Step 3: Infer ─────────────────────────────────────────────────────
        $inferResult = $this->callInfer($preprocessed);

        // ── Step 4: Persist ───────────────────────────────────────────────────
        return $this->persistResults($senior, $survey, $preprocessed, $inferResult);
    }

    /**
     * Preprocess only (no inference) — useful for batch jobs.
     */
    public function preprocess(array $raw): array
    {
        return $this->callPreprocess($raw);
    }

    /**
     * Check if Python services are reachable.
     */
    public function healthCheck(): array
    {
        $results = [];
        foreach ([
            'preprocessor' => $this->preprocessUrl . '/health',
            'inference'    => $this->inferenceUrl  . '/health',
        ] as $name => $url) {
            try {
                $resp = Http::timeout(max(3, $this->timeout))->get($url);
                $results[$name] = $resp->successful() ? 'ok' : 'error';
            } catch (\Exception $e) {
                $results[$name] = 'unreachable';
            }
        }
        return $results;
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private function buildRawPayload(SeniorCitizen $senior, QolSurvey $survey): array
    {
        return [
            // Profile fields
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

            // QoL survey responses
            'qol_responses'           => $survey->toFeatureArray(),
        ];
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
            // Cold start can exceed default timeout while model artifacts are loaded.
            if (str_contains(strtolower($e->getMessage()), 'timed out')) {
                try {
                    $response = Http::connectTimeout(5)
                        ->timeout(max($this->timeout, $this->coldStartTimeout))
                        ->post($this->preprocessUrl . '/preprocess', $raw);

                    if ($response->successful()) {
                        $this->preprocessAvailable = true;
                        return $response->json();
                    }
                } catch (\Exception $retryException) {
                    Log::warning('Preprocess service retry failed after timeout', ['error' => $retryException->getMessage()]);
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
                } catch (\Exception $retryException) {
                    Log::warning('Inference service retry failed after timeout', ['error' => $retryException->getMessage()]);
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

            Log::warning("{$serviceName} service health check failed; using fallback mode", [
                'status' => $resp->status(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::warning("{$serviceName} service unreachable; using fallback mode", [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function localPreprocessOrFallback(array $raw): array
    {
        $result = $this->runLocalPythonStage('preprocess', $raw);

        if ($result !== null) {
            return $result;
        }

        return $this->fallbackPreprocess($raw);
    }

    private function localInferOrFallback(array $preprocessed): array
    {
        $result = $this->runLocalPythonStage('infer', $preprocessed);

        if ($result !== null) {
            return $result;
        }

        return $this->fallbackInfer($preprocessed);
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

            $decoded['warnings'] = array_values(array_unique(array_merge(
                $decoded['warnings'] ?? [],
                ['Served by local Python runner because HTTP ML services were unavailable.']
            )));

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

        $candidates = [
            base_path('python/venv/Scripts/python.exe'),
            base_path('python/venv/bin/python'),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function pythonEnvironment(): array
    {
        $env = [];
        $modelsPath = env('ML_MODELS_PATH');

        if (is_string($modelsPath) && trim($modelsPath) !== '') {
            $env['ML_MODELS_PATH'] = trim($modelsPath);
        }

        $enableNotebookOverrides = env('ENABLE_NOTEBOOK_OVERRIDES');
        if ($enableNotebookOverrides !== null) {
            $env['ENABLE_NOTEBOOK_OVERRIDES'] = (string) $enableNotebookOverrides;
        }

        return $env;
    }

    private function persistResults(
        SeniorCitizen $senior,
        QolSurvey $survey,
        array $preprocessed,
        array $inferResult
    ): MlResult {
        $cluster   = $inferResult['cluster'] ?? [];
        $scores    = $inferResult['risk_scores'] ?? [];
        $levels    = $inferResult['risk_levels'] ?? [];

        /** @var MlResult $mlResult */
        $mlResult = MlResult::updateOrCreate(
            ['senior_citizen_id' => $senior->id, 'qol_survey_id' => $survey->id],
            [
                'model_version'    => 'v1',
                'cluster_id'       => $cluster['raw_id'] ?? null,
                'cluster_named_id' => $cluster['named_id'] ?? null,
                'cluster_name'     => $cluster['name'] ?? null,
                'ic_risk'          => $scores['ic_risk'] ?? null,
                'env_risk'         => $scores['env_risk'] ?? null,
                'func_risk'        => $scores['func_risk'] ?? null,
                'composite_risk'   => $scores['composite_risk'] ?? null,
                'wellbeing_score'  => $scores['wellbeing_score'] ?? null,
                'ic_risk_level'    => $levels['ic'] ?? null,
                'env_risk_level'   => $levels['env'] ?? null,
                'func_risk_level'  => $levels['func'] ?? null,
                'overall_risk_level' => $levels['overall'] ?? null,
                'section_scores'   => $preprocessed['section_scores'] ?? null,
                'raw_output'       => $inferResult,
                'processed_at'     => now(),
            ]
        );

        // Persist recommendations
        $mlResult->recommendations()->delete(); // clear old ones
        foreach ($inferResult['recommendations'] ?? [] as $rec) {
            Recommendation::create([
                'ml_result_id'      => $mlResult->id,
                'senior_citizen_id' => $senior->id,
                'priority'          => $rec['priority'],
                'type'              => $rec['type'],
                'domain'            => $rec['domain'] ?? null,
                'category'          => $rec['category'] ?? null,
                'action'            => $rec['action'],
                'urgency'           => $rec['urgency'] ?? null,
                'risk_level'        => $rec['risk_level'] ?? null,
            ]);
        }

        return $mlResult->fresh(['recommendations']);
    }

    /**
     * Heuristic fallback when Python service is unavailable.
     */
    private function fallbackPreprocess(array $raw): array
    {
        $age = (int) ($raw['age'] ?? 70);
        $wellbeing = 0.5;

        return [
            'status'          => 'success_fallback',
            'encoded_features'=> ['age' => $age],
            'scaled_features' => array_fill(0, 35, 0.0),
            'reduced_features'=> array_fill(0, 10, 0.0),
            'section_scores'  => [
                'sec1_age_risk'       => max(0, ($age - 60) / 40),
                'sec2_family_support' => 0.5,
                'sec3_hr_score'       => 0.5,
                'sec4_dependency_risk'=> 0.3,
                'sec5_eco_stability'  => 0.5,
                'sec6_health_score'   => 0.5,
                'overall_wellbeing'   => $wellbeing,
            ],
            'who_domain_scores' => [
                'ic_score' => 3.0, 'env_score' => 3.0,
                'func_score' => 3.0, 'qol_score' => 3.0,
            ],
        ];
    }

    private function fallbackInfer(array $preprocessed): array
    {
        $wellbeing = $preprocessed['section_scores']['overall_wellbeing'] ?? 0.5;
        $composite = round(1 - $wellbeing, 4);
        $level = $composite > 0.75 ? 'CRITICAL' : ($composite > 0.65 ? 'HIGH' : ($composite > 0.45 ? 'MODERATE' : 'LOW'));
        $clusterId = $composite > 0.65 ? 3 : ($composite > 0.45 ? 2 : 1);

        return [
            'status'  => 'success_fallback',
            'cluster' => [
                'raw_id' => $clusterId - 1, 'named_id' => $clusterId,
                'name'   => ['','High Functioning','Moderate / Mixed Needs','Low Functioning / Multi-Domain Risk'][$clusterId],
                'ic' => 'Unknown', 'env' => 'Unknown', 'func' => 'Unknown',
            ],
            'risk_scores' => [
                'ic_risk' => $composite, 'env_risk' => $composite,
                'func_risk' => $composite, 'composite_risk' => $composite,
                'wellbeing_score' => $wellbeing,
            ],
            'risk_levels' => [
                'ic' => strtolower($level), 'env' => strtolower($level),
                'func' => strtolower($level), 'overall' => $level,
            ],
            'recommendations' => [
                ['priority' => 1, 'type' => 'general', 'category' => 'general',
                 'action' => 'ML service unavailable. Please re-run analysis when service is restored.',
                 'urgency' => 'planned', 'domain' => 'general'],
            ],
            'warnings' => ['Python ML services are currently unreachable. Fallback heuristics used.'],
        ];
    }
}
