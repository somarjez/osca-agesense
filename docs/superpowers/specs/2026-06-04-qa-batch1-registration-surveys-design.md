# QA Batch 1 — Registration & Surveys

**Date:** 2026-06-04
**Status:** Approved design, pending implementation plan
**Source:** QA Testing punch list (June 4, 2026), Batch 1 of 6

## Context

QA testing on 2026-06-04 produced a ~30-issue punch list across 13 modules. It was
decomposed by module/page into 6 batches, each getting its own design → plan →
implement cycle. This is **Batch 1 — Registration & Surveys**.

Scope is limited to the profile-registration and QoL-survey flows. ML label fixes,
PDF redesign, and the duplicate-recommendations bug belong to Batch 2 and are
explicitly out of scope here.

## Affected code (current state)

- `app/Livewire/Surveys/ProfileSurvey.php` — registration wizard. On `save()` sets
  `$saved = true`, dispatches `profile-saved`, flashes a session success message.
- `resources/views/livewire/surveys/profile-survey.blade.php:36-44` — renders the
  current inline `<x-alert>` success banner with OSCA ID + "Take QoL Survey" link.
- `app/Livewire/Surveys/QolSurveyForm.php` — QoL survey form. `mount()` accepts an
  optional `surveyId` and repopulates via `populateFromSurvey()`. `saveDraft()` uses
  `updateOrCreate(['senior_citizen_id', 'status' => 'draft'])` (one draft per senior).
  `submitSurvey()` creates a NEW survey when `$this->survey` is null.
- `resources/views/livewire/surveys/qol-survey-form.blade.php:203-220` — the 1-5
  rating buttons (radio + `peer-checked` styling).
- `app/Http/Controllers/SurveyController.php` — `qolIndex()` (status + barangay
  filters, paginate 20), `qolCreate()`, `qolEdit()` (loads create view with surveyId).
- `resources/views/surveys/qol/index.blade.php` — QoL list; filter form (status +
  barangay), table; draft rows currently expose a generic "Edit" link.
- `resources/views/seniors/show.blade.php` — senior record page (gains a draft CTA).
- Reusable building blocks: `<x-modal>`, `<x-confirm-modal>`, `btn`/`btn-primary`/
  `btn-secondary` tokens, forest/paper/ink design tokens.

## Requirements

### 1. Profile-save success modal

Replace the inline success banner with a centered modal built on `<x-modal>`,
triggered by the existing `profile-saved` event / `$saved` state.

- Content: success icon (forest tone), "Profile Saved" heading, the OSCA ID
  rendered large and monospaced.
- Two actions:
  - **+ Take QoL Survey →** (`btn-primary`) → navigate to
    `route('surveys.qol.create', $senior)`.
  - **← Back to Profile** (`btn-secondary`) → navigate to
    `route('seniors.show', $senior)`.
- Modal is **not dismissible by backdrop click** — the user must pick an action.
- Remove the old `<x-alert>` success banner block.

### 2. Draft surveys — openable and not stranded

- **QoL list** (`index.blade.php`): for `status === 'draft'` rows, replace the generic
  "Edit" action with a prominent **"Continue Draft"** button (forest/primary tone,
  not ghost). Non-draft rows keep their existing Edit / Results actions.
- **Senior record page** (`seniors.show`): when the senior has a draft QoL survey,
  surface a **"Continue draft"** CTA; otherwise show the normal "New QoL Survey"
  entry. (This touches Batch 2's page but is required for the draft entry point.)
- **Root-cause fix (stranded drafts):** starting a "new" survey for a senior who
  already has a draft must route into the existing draft rather than creating a
  duplicate record. Confirm the exact current behavior via systematic-debugging
  **before** changing it; the fix should make `qolCreate`/the "new survey" entry
  resolve to the senior's existing draft when one exists.

### 3. QoL Surveys — search

Add a text **Search** input to the existing filter form in `index.blade.php`.

- Matches senior **full name OR OSCA ID** (case-insensitive, partial).
- Implemented in `qolIndex()` as a `when($request->search, …)` clause using
  `whereHas('seniorCitizen', …)`, composed with the existing status/barangay filters.
- Preserves pagination and query string (`withQueryString()` already present).

### 4. Rating-button active state (dark-mode feedback)

Strengthen the checked state of the 1-5 rating buttons
(`qol-survey-form.blade.php:213-220`).

- Current `peer-checked:bg-forest-700 peer-checked:text-paper` is too low-contrast in
  dark mode, so a selected number is hard to perceive.
- New active state: filled forest background **+ ring** (e.g.
  `peer-checked:ring-2 peer-checked:ring-forest-400 peer-checked:ring-offset-1`) **+**
  a subtle shadow/scale, so the selected value is unmistakable in both light and dark
  themes.
- Apply the same treatment to any sibling radio-style option groups that share this
  pattern, for consistency.

## Design decisions

- **Modal forces an explicit choice** (no backdrop dismiss) — confirmed with user.
- **Search scope = name + OSCA ID** — confirmed sufficient; barangay remains a
  separate dropdown filter.
- **Back to Profile = senior's record page** (`seniors.show`), since the senior now
  exists as a saved record.
- **No new design language** — reuse existing `<x-modal>`, button tokens, and
  forest/paper/ink palette.

## Out of scope (later batches)

- ML Analysis label fixes, Export PDF / Ctrl+P redesign, duplicate-recommendations
  bug, Recommendations search → **Batch 2**.
- Health Groups, GIS, Reports, Admin/Misc → Batches 3-6.

## Verification

- Save a new profile → modal appears with correct OSCA ID; both buttons navigate
  correctly; backdrop click does not dismiss.
- Save a draft survey, return via QoL list "Continue Draft" → form repopulates.
- Save a draft, open the senior's record page → "Continue draft" CTA present and
  resumes the same draft (no duplicate created on submit).
- QoL list search by partial name and by OSCA ID returns correct rows; combines with
  status/barangay filters; pagination preserves the query.
- In dark mode, selecting a rating number shows an unmistakable active state.
