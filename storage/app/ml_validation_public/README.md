# ml_validation_public

**Generated. De-identified. Safe to commit. Do not hand-edit.**

This folder is produced by `php artisan ml:export-validation` (see
`app/Console/Commands/ExportValidationArtifacts.php`). It is a whitelisted,
scrubbed copy of the model-evaluation artifacts normally kept in the
gitignored `storage/app/ml_validation/` mirror, so the admin **System
Validation** page (`/reports/validation`) can populate on any machine after a
plain `git pull` — no notebook run, no `osca_output/`, no PII required.

## What's in here

- `reports/` — aggregate CSV/JSON evaluation metrics (clustering quality,
  regression CV scores, classification confusion matrix, XAI significance
  tests, etc.), plus two per-senior report files rewritten to keep only the
  columns the page aggregates (no name, no barangay, no senior_id).
- `plots/` — the 12 whitelisted evaluation PNGs.

## What's deliberately **not** in here

- `cache_vs_live_model_comparison.csv` (the live-model-vs-notebook
  "concordance" pillar) is never exported — it lists individual seniors by
  name/barangay/age. On a pulled clone that pillar simply doesn't render
  (`ModelValidation::fidelity()` degrades gracefully when the file is absent).
  It can be regenerated locally on a machine with the live DB + models.
- `senior_recommendations.json` / `senior_predictions.csv` — full per-senior
  dumps, unused by the validation page.

## Regenerating

On the training machine, after `php artisan ml:sync-validation`:

```
php artisan ml:export-validation
git add storage/app/ml_validation_public
git commit -m "docs: refresh de-identified validation artifacts"
git push
```

The command runs a PII guard after writing every file — it deletes and fails
on anything whose CSV header or JSON keys look identifying (`name`,
`full_name`, `senior_id`, `barangay`), so an accidental whitelist mistake
can't leak PII into git.

See `docs/UPDATING_THE_MODEL.md` for the full training-device runbook.
