<?php

namespace Tests\Unit;

use App\Support\RiskThresholds;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for TC-ML-06 (audit finding): the PHP fallback-scoring
 * path used to hardcode HIGH>=0.50/MODERATE>=0.30 while the live Python model
 * used the tuned 0.54/0.39 — a senior scored during an ML outage could land
 * in a different risk band than the same senior scored live.
 * RiskThresholds::load() is now the single source both languages read from
 * python/models/risk_thresholds.json (Python's mirror check lives in
 * python/tests/test_inference_paths.py's "_priority_flag thresholds" block).
 */
class RiskThresholdsTest extends TestCase
{
    #[Test]
    public function loads_the_tuned_values_from_the_shared_json_file(): void
    {
        $thresholds = RiskThresholds::load();

        $this->assertSame(0.70, $thresholds['critical']);
        $this->assertSame(0.54, $thresholds['high']);
        $this->assertSame(0.39, $thresholds['moderate']);
    }

    #[Test]
    public function falls_back_to_the_same_tuned_defaults_when_the_file_is_missing(): void
    {
        // A models path with no risk_thresholds.json at all — load() must not
        // throw and must not silently revert to the old 0.50/0.30 numbers.
        putenv('ML_MODELS_PATH='.sys_get_temp_dir().'/osca-nonexistent-models-dir-'.uniqid());

        try {
            $thresholds = RiskThresholds::load();
            $this->assertSame(0.70, $thresholds['critical']);
            $this->assertSame(0.54, $thresholds['high']);
            $this->assertSame(0.39, $thresholds['moderate']);
        } finally {
            putenv('ML_MODELS_PATH'); // unset — restore real env for other tests
        }
    }
}
