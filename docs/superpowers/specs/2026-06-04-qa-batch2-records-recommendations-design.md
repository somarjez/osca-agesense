# QA Batch 2 — Individual Records & Recommendations

**Date:** 2026-06-04
**Status:** Approved design, pending implementation plan
**Source:** QA Testing punch list (June 4, 2026), Batch 2 of 6

## Context

Second of six module-scoped batches decomposed from the 2026-06-04 QA punch list.
Covers the individual senior record's ML Analysis presentation, the Export PDF /
print layout, and the Recommendations module (a real duplicate-data bug + search).

Batch 1 (Registration & Surveys) shipped in PR #74. Health Groups, GIS, Reports, and
Admin/Misc remain as Batches 3-6.

## Affected code (current state)

- `resources/views/seniors/show.blade.php`
  - Compact assessment strip (`:180-243`): domain bars labeled `Physical`/
    `Environment`/`Functioning` (`:202-206`); `<x-risk-badge :level="$ml->overall_risk_level" />`
    renders a bare "HIGH" (`:186`); cluster badge at `:194`.
  - Risk Drivers / XAI section (`:256-260`): domain labels `Physical Capacity` / `Environment` /
    `Daily Functioning`.
- `resources/views/seniors/pdf.blade.php` — print/PDF template. Header text at `:90-94`
  ("OSCA — Senior Citizen Affairs" / "Office for Senior Citizens Affairs" /
  "Senior Citizen Profile Report"). Palette mixes forest (`#2d6a4f`) with leftover teal
  (`#f0fdfa`, `#99f6e4`, `#134e4a`, `#0f766e`, `tag-teal`).
- `app/Http/Controllers/RecommendationController.php`
  - `index()` — global `$stats` via `Recommendation::count()` etc.; per-senior counts via
    `withCount(['recommendations', ...])`; filters: barangay, risk, has_urgent.
  - `show()` — `$senior->recommendations()->with('mlResult')->orderBy('priority')->get()`.
- `app/Models/SeniorCitizen.php` — `recommendations()` is `hasMany(Recommendation)` (ALL
  results); `latestMlResult()` is `hasOne(MlResult)->latestOfMany()`; `mlResults()` hasMany.
- `app/Models/MlResult.php` — `recommendations()` hasMany.
- `app/Services/MlService.php:1004-1038` — on persist, deletes a result's old recs and
  re-inserts fresh ones (so duplication is NOT within one result).
- `resources/views/recommendations/index.blade.php` — filter row + senior list.

## Root cause: duplicate recommendations

`MlResult::updateOrCreate(['senior_citizen_id','qol_survey_id'], …)` creates one ML
result per (senior, survey), and `persistResults()` clears+reinserts that result's recs —
so re-running the SAME survey does not duplicate. Duplicates arise because the
per-senior **recommendations view aggregates across ALL of a senior's ML results**
(`$senior->recommendations()` hasMany). A senior with more than one assessment (a second
survey, or historical results) accumulates near-identical recommendation sets, shown and
counted together. Global index stats (`Recommendation::count()`, `withCount`) are inflated
the same way.

## Requirements

### 1. ML Analysis label fixes (`seniors/show.blade.php`)

Standardize domain terminology on WHO language matching the `ic_risk`/`func_risk` fields:

- Assessment strip domain bars (`:202-206`):
  `Physical` → **Intrinsic Capacity**; `Functioning` → **Functional Ability**;
  `Environment` unchanged.
- XAI section domain labels (`:256-260`):
  `Physical Capacity` → **Intrinsic Capacity**; `Daily Functioning` → **Functional Ability**;
  `Environment` unchanged.
- Risk badge (`:186`): add a small **"Overall risk"** caption adjacent to / above the
  `<x-risk-badge>` so the value (e.g. "HIGH") reads unambiguously as a risk level. Keep the
  existing badge component.
- Verify the cluster badge (`:194`, `<x-cluster-badge>`) renders the full label
  (e.g. "Group 4 · Low Functioning / Multi-Domain Priority Seniors") without truncation;
  fix if clipped.

No other changes to the assessment strip.

### 2. Export PDF / print redesign (`seniors/pdf.blade.php`)

Rebuild as an **official government document**:

- **Letterhead header**: reuse the existing org identity ("Office for Senior Citizens
  Affairs" / "OSCA"); do NOT fabricate a municipality name not already present. Include
  report title ("Senior Citizen Profile Report"), OSCA ID, and generated date, laid out
  as a formal letterhead.
- **Consistent forest palette**: replace all leftover teal (`#f0fdfa`, `#99f6e4`,
  `#134e4a`, `#0f766e`, `tag-teal`) with the forest family (`#2d6a4f` and neutral greys
  already used elsewhere in the template).
- **Formal ruled sections**: consistent uppercase section headers with hairline rules.
- **Footer**: "Generated on {date} by {user}" plus a signature block
  (Prepared by ____ / Verified by ____).
- Apply the same label fixes (Intrinsic Capacity / Functional Ability / "Overall risk:
  HIGH") so print matches screen.

### 3. Duplicate recommendations fix (`RecommendationController` + `SeniorCitizen`)

Scope recommendations to each senior's **latest ML result** (current assessment).
Historical results' recommendations remain in the database but are not shown or counted.

- Add a **`currentRecommendations()`** relationship on `SeniorCitizen` returning only the
  recommendations whose `ml_result_id` is the senior's latest ML result. Express the
  latest-result scoping once and reuse it.
- `show()`: read from `currentRecommendations` (with `mlResult`, ordered by priority).
- `index()`:
  - Per-senior counts (`recommendations_count`, `pending_count`, `immediate_count`) scope
    to current recommendations.
  - `whereHas('recommendations')` / `has_urgent` filters scope to current recommendations.
  - Top-of-page `$stats` (total / pending / immediate / seniors) count only current
    recommendations, so totals reconcile with the per-senior counts.
- Behavior when the latest result has zero recommendations: the senior shows no recs
  (correct — reflects the current assessment).

### 4. Recommendations search (`recommendations/index.blade.php` + controller)

Add a **search by senior full name or OSCA ID** to the index filter row (same pattern as
Batch 1's QoL search: `when($request->search, …)` with a name/OSCA `whereHas`/`where`).
Compose with the existing barangay / risk / has_urgent filters; preserve pagination and
query string.

## Design decisions

- **Recommendations reflect the current assessment only** (latest ML result), everywhere —
  per-senior view, per-senior counts, and global stats — so numbers reconcile. History is
  retained in the DB, just not surfaced. (User-confirmed.)
- **PDF = official government document** style. (User-confirmed.)
- **No fabricated LGU identity** — reuse existing configured org text.
- **WHO terminology** (Intrinsic Capacity / Functional Ability) standardized across screen
  and print.

## Out of scope (later batches)

- Health Groups, GIS, Reports, Admin/Misc → Batches 3-6.
- The v2 recommendation engine experiment on `feature/improved-recommendations` (stale,
  not adopted here).

## Verification

- Senior record ML strip shows "Intrinsic Capacity", "Environment", "Functional Ability",
  and an "Overall risk" caption next to the level; cluster label shown in full.
- Export PDF renders an official-document layout: forest-only palette, letterhead,
  signature footer, corrected labels.
- A senior with 2+ ML results: Recommendations page shows only the latest result's recs
  (no duplicates); per-senior counts and global stats reflect current recs only.
- Recommendations index search by partial name and by OSCA ID returns correct seniors;
  composes with existing filters; pagination preserves the query.
- Full `php artisan test` suite passes; new tests cover the dedup scoping and search.
