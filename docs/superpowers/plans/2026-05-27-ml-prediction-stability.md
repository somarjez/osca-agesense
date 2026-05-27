# ML Prediction Stability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore validated risk predictions on all devices and add a diagnostic tool to identify why the live GBR/RFR models under-predict risk.

**Architecture:** Two independent fixes in sequence. Task 1–2 flip the safe default so every device uses the notebook-validated CSV until further notice. Task 3–4 add a diagnostic artisan command that runs a single senior through the raw live model and prints a side-by-side comparison against the CSV values — this output tells us exactly where the scoring gap opens so Task 5 can fix it.

**Tech Stack:** Python 3 (Flask inference service, local_ml_runner.py), PHP 8/Laravel 11 (Artisan command), pandas/csv for notebook comparison.

---

## File Map

| File | Action | Purpose |
|------|--------|---------|
| `python/services/inference_service.py` | Modify line 131, add 3 lines after 134 | Flip default; add startup warning |
| `app/Console/Commands/MlDiagnoseSenior.php` | Create | New artisan command |

---

## Task 1: Flip ENABLE_NOTEBOOK_OVERRIDES default to True

**Files:**
- Modify: `python/services/inference_service.py:131`

### Background

`ENABLE_NOTEBOOK_OVERRIDES` defaults to `False` (line 131). When `.env` does not set this variable, `pythonEnvironment()` in `MlService.php` omits it from the subprocess env (line 821–823), so the Python service uses the module-level default. Flipping the default to `True` makes every device safe without any `.env` changes.

- [ ] **Step 1: Open the file and find the flag**

Open `python/services/inference_service.py`. Locate line 131:

```python
ENABLE_NOTEBOOK_OVERRIDES = _env_flag("ENABLE_NOTEBOOK_OVERRIDES", False)
```

- [ ] **Step 2: Change the default and add startup warning**

Replace lines 131–134 with:

```python
ENABLE_NOTEBOOK_OVERRIDES = _env_flag("ENABLE_NOTEBOOK_OVERRIDES", True)
# Default True: nearest-centroid in scaled space is deterministic across devices.
# Set ENABLE_DETERMINISTIC_CLUSTER=false in .env only for debugging UMAP behaviour.
ENABLE_DETERMINISTIC_CLUSTER = _env_flag("ENABLE_DETERMINISTIC_CLUSTER", True)

if not ENABLE_NOTEBOOK_OVERRIDES:
    logger.warning(
        "ENABLE_NOTEBOOK_OVERRIDES=false — live GBR/RFR model active. "
        "Results may deviate from the validated notebook baseline. "
        "Set ENABLE_NOTEBOOK_OVERRIDES=true (or remove the env var) to restore validated predictions."
    )
```

- [ ] **Step 3: Verify the file looks correct**

Run (from the `python/services/` directory):

```bash
python -c "
import os, sys
os.environ['ENABLE_NOTEBOOK_OVERRIDES'] = 'false'
sys.path.insert(0, '.')
import inference_service
print('ENABLE_NOTEBOOK_OVERRIDES =', inference_service.ENABLE_NOTEBOOK_OVERRIDES)
"
```

Expected output:
```
ENABLE_NOTEBOOK_OVERRIDES = False
```

(The warning will also appear in stderr — that's correct.)

Then verify the default (no env var):

```bash
python -c "
import sys; sys.path.insert(0, '.')
import inference_service
print('ENABLE_NOTEBOOK_OVERRIDES =', inference_service.ENABLE_NOTEBOOK_OVERRIDES)
"
```

Expected:
```
ENABLE_NOTEBOOK_OVERRIDES = True
```

- [ ] **Step 4: Commit**

```bash
git add python/services/inference_service.py
git commit -m "fix: default ENABLE_NOTEBOOK_OVERRIDES to true for all devices

Without an explicit env var override, every device now uses the
notebook-validated senior_predictions.csv baseline instead of the
raw GBR/RFR model output, which under-predicts risk.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 2: Restore validated predictions via batch re-analysis

**Files:** None (artisan command only)

### Background

After flipping the default, any device that already ran `ml:batch-analyze` with the old default will have `prediction_source = live_model` rows in the DB. Running `--force` regenerates all rows using the notebook cache.

- [ ] **Step 1: Restart the Flask inference service (if running)**

If Flask is running (HTTP mode), it loaded the old `False` default at startup. Kill and restart it so the new default takes effect:

```bash
# Stop any running inference_service process, then restart:
php artisan serve   # (or your usual server start)
```

If using local subprocess mode (no Flask), no restart is needed — the subprocess inherits the new default on each call.

- [ ] **Step 2: Run batch analysis with force**

```bash
php artisan ml:batch-analyze --force
```

Expected output (all 283 seniors, all `notebook_cache`):

```
=== Batch Summary ===
+--------------------------------+-------+
| Category                       | Count |
+--------------------------------+-------+
| Missing → computed             | 283   |
| Failed                         | 0     |
|                                |       |
| Result: notebook_cache         | 283   |
| Result: live_model             | 0     |
| Result: fallback               | 0     |
+--------------------------------+-------+
```

If any seniors come back as `live_model` (not in the CSV), run the strict check to identify them:

```bash
php artisan ml:batch-analyze --strict-notebook-cache
```

- [ ] **Step 3: Verify dashboard distribution matches baseline**

Open the dashboard and confirm the Risk Distribution is close to the validated notebook baseline:

| Level | Notebook baseline | Acceptable range |
|-------|------------------|------------------|
| HIGH  | 54 (53 + 1 CRITICAL remapped) | 50–58 |
| MODERATE | 191 | 185–197 |
| LOW   | 38 | 34–42 |

- [ ] **Step 4: Commit DB state note (no code change)**

No commit needed — this task only runs an artisan command. Record the verified counts in a comment on the PR.

---

## Task 3: Add `ml:diagnose-senior` artisan command

**Files:**
- Create: `app/Console/Commands/MlDiagnoseSenior.php`

### Background

This command runs one senior through the live model (forced `ENABLE_NOTEBOOK_OVERRIDES=false`) and prints a comparison table against their notebook CSV values. It reveals exactly where the scoring gap opens: missing features defaulting to 0.0, model under-prediction, or formula drift.

The command spawns `local_ml_runner.py combined` with `ENABLE_NOTEBOOK_OVERRIDES=0` injected into the subprocess environment, regardless of what `.env` says. It then looks up the senior in `python/models/predictions/senior_predictions.csv` by first/last name match.

- [ ] **Step 1: Create the command file**

Create `app/Console/Commands/MlDiagnoseSenior.php`:

```php
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

        // Print feature_map keys that were present so we can spot 0.0 defaults
        $featureMap = $live['debug_feature_map'] ?? null;
        if ($featureMap) {
            $this->line('');
            $this->info("=== Feature Map (live model input) ===");
            $rows = [];
            foreach ($featureMap as $k => $v) {
                $rows[] = [$k, is_float($v) ? number_format($v, 4) : $v];
            }
            $this->table(['Feature', 'Value'], $rows);
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
        ];
        foreach ($winCandidates as $p) {
            if (is_file($p)) return $p;
        }

        return null;
    }
}
```

- [ ] **Step 2: Verify the command is auto-discovered**

Laravel 11 auto-discovers commands in `app/Console/Commands/`. Confirm:

```bash
php artisan list | grep diagnose
```

Expected:
```
ml:diagnose-senior   Compare live-model scores vs notebook CSV for a single senior (diagnostic tool).
```

- [ ] **Step 3: Run on one senior to confirm it works**

Pick any senior ID (e.g., ID 1):

```bash
php artisan ml:diagnose-senior 1
```

Expected: a comparison table with IC/ENV/FUNC/Composite/Level rows, no PHP error.
The Δ values may be large — that's the data we need, not a failure.

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/MlDiagnoseSenior.php
git commit -m "feat: add ml:diagnose-senior artisan command

Runs a single senior through the live GBR/RFR model (ENABLE_NOTEBOOK_OVERRIDES=false
forced) and prints a side-by-side comparison against senior_predictions.csv.
Used to diagnose why live model risk scores diverge from validated notebook values.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 4: Run diagnostic and document the scoring gap

**Files:** None (investigation + documentation only)

### Background

Run `ml:diagnose-senior` on five seniors from the notebook CSV who were classified HIGH.
The comparison output will show exactly where the gap opens. Use this to identify the
root cause before writing any fixes.

- [ ] **Step 1: Get 5 HIGH-risk senior IDs from the database**

```bash
php artisan tinker
```

If tinker is unavailable, create a temp PHP script:

```php
<?php
// tmp_high_ids.php — run with: php tmp_high_ids.php, then delete it
define('LARAVEL_START', microtime(true));
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ids = App\Models\MlResult::where('risk_level', 'HIGH')
    ->orderByDesc('composite_risk')
    ->limit(5)
    ->pluck('senior_citizen_id');

foreach ($ids as $id) {
    $s = App\Models\SeniorCitizen::find($id);
    echo "ID {$id}: {$s->first_name} {$s->last_name}\n";
}
```

Run: `php tmp_high_ids.php` then delete it.

- [ ] **Step 2: Run diagnostic on each of the 5 seniors**

For each ID returned above:

```bash
php artisan ml:diagnose-senior <ID>
```

Record the output for each senior. Pay attention to:
- Which domain has the largest negative Δ (IC, ENV, or FUNC)
- Whether all three domains are lower or just one
- The composite Δ (live − csv)

- [ ] **Step 3: Document findings in a comment on the PR**

Post the comparison tables as a PR comment so the root cause is recorded.
The most likely findings and their fixes are listed in Task 5.

---

## Task 5: Fix the root cause (apply after Task 4 diagnosis)

**Files:** Depends on finding (see branches below)

### Background

Based on the diagnostic output from Task 4, one of three paths applies.
Implement only the path that matches your findings.

---

### Path A — Models were retrained after the CSV was generated

**Symptom:** GBR/RFR predictions are consistently lower than CSV `ml_ic_risk` / `ml_env_risk` / `ml_func_risk` values, even when features look correct.

**Fix:** Export a fresh validated baseline from the current live model.

- [ ] **Step A-1: Run batch analysis in live-model mode to get current scores**

Set `ENABLE_NOTEBOOK_OVERRIDES=false` in `.env`, then:

```bash
php artisan ml:batch-analyze --force
```

- [ ] **Step A-2: Export the current DB predictions as the new CSV baseline**

Create `tmp_export_predictions.php`:

```php
<?php
define('LARAVEL_START', microtime(true));
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$results = App\Models\MlResult::with('seniorCitizen', 'seniorCitizen.latestQolSurvey')
    ->whereHas('seniorCitizen', fn($q) => $q->active())
    ->get();

$out = fopen(base_path('python/models/predictions/senior_predictions_live.csv'), 'w');
fputcsv($out, ['first_name','last_name','barangay','age','gender','marital_status',
               'cluster_id','cluster_name','composite_risk','risk_level',
               'ml_ic_risk','ml_env_risk','ml_func_risk']);

foreach ($results as $r) {
    $s = $r->seniorCitizen;
    fputcsv($out, [
        $s->first_name, $s->last_name, $s->barangay, $s->age, $s->gender, $s->marital_status,
        $r->cluster_id, $r->cluster_name, round($r->composite_risk, 4), $r->risk_level,
        round($r->ml_ic_risk  ?? 0, 4),
        round($r->ml_env_risk ?? 0, 4),
        round($r->ml_func_risk ?? 0, 4),
    ]);
}
fclose($out);
echo "Exported " . $results->count() . " rows to senior_predictions_live.csv\n";
```

Run: `php tmp_export_predictions.php` then delete the file.

- [ ] **Step A-3: Review the export, then replace the baseline**

Open `python/models/predictions/senior_predictions_live.csv` and verify 5–10 rows look sensible (no 0.0 scores, realistic risk levels). Then replace:

```bash
cp python/models/predictions/senior_predictions_live.csv \
   python/models/predictions/senior_predictions.csv
```

- [ ] **Step A-4: Restore notebook overrides and re-run batch**

Remove `ENABLE_NOTEBOOK_OVERRIDES=false` from `.env` (or set it back to `true`), then:

```bash
php artisan ml:batch-analyze --force
```

Verify all 283 return `notebook_cache`.

- [ ] **Step A-5: Commit**

```bash
git add python/models/predictions/senior_predictions.csv
git commit -m "data: refresh senior_predictions.csv from current live model

Previous CSV was generated with an older model bundle. Refreshed from
the current GBR/RFR ensemble output after verifying distribution is
consistent with the validated notebook baseline.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

### Path B — Specific features are 0.0 in live model but non-zero in CSV

**Symptom:** The diagnostic output shows specific `feature_map` values are 0.0 that clearly shouldn't be (e.g., `sec3_community_score = 0.0000` but CSV `ml_ic_risk` is high). This means the preprocessor isn't computing that feature.

**Fix:** Find the mismatched feature key and add the missing computation to `preprocess_service.py`.

- [ ] **Step B-1: Identify the missing feature**

Run the diagnostic and note every feature that is `0.0000` but logically should be non-zero for this senior (e.g., `phy_energy = 0.0` for a mobile senior is wrong).

- [ ] **Step B-2: Trace the feature in preprocess_service.py**

In `python/services/preprocess_service.py`, search for the feature name:

```bash
grep -n "sec3_community_score\|phy_energy"  python/services/preprocess_service.py
```

Check whether the key is being written to `enc`. If it's in `section_scores` but not copied to `enc`, add the copy (following the pattern at lines 866–884):

```python
enc["<feature_name>"] = section_scores["<feature_name>"]
```

- [ ] **Step B-3: Re-run diagnostic to confirm the fix**

```bash
php artisan ml:diagnose-senior <same_senior_id>
```

Verify the previously-zero feature now has a non-zero value and the Δ on composite_risk is closer to 0.

- [ ] **Step B-4: Re-run batch and verify distribution**

```bash
php artisan ml:batch-analyze --force
```

Verify the HIGH/MODERATE/LOW distribution is within the acceptable ranges from Task 2 Step 3.

- [ ] **Step B-5: Commit**

```bash
git add python/services/preprocess_service.py
git commit -m "fix: copy missing feature to enc for ML risk model input

<feature_name> was computed in section_scores but not written back
to enc, causing it to default to 0.0 in the GBR/RFR feature vector
and systematically under-predicting risk.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```
