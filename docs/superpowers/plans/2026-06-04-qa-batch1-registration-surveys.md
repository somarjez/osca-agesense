# QA Batch 1 — Registration & Surveys Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the profile-registration and QoL-survey UX issues from the June 4 2026 QA punch list (Batch 1 of 6): a save-success modal, openable/non-stranded draft surveys, QoL list search, and a dark-mode-visible rating-button active state.

**Architecture:** Laravel 11 + Livewire 3, Blade views with a Tailwind design system (forest/paper/ink tokens, reusable `<x-modal>`/`<x-confirm-modal>` components). Changes touch two Livewire components (`ProfileSurvey`, `QolSurveyForm`), one controller (`SurveyController` + `SeniorCitizenController@show`), and three Blade views. Tests use PHPUnit feature tests with `DatabaseTransactions` and `Livewire::test()`.

**Tech Stack:** PHP 8.2, Laravel, Livewire 3, Tailwind CSS, Alpine.js, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-06-04-qa-batch1-registration-surveys-design.md`

---

## File Structure

- `resources/views/livewire/surveys/profile-survey.blade.php` — replace inline success `<x-alert>` (lines 36-44) with an `<x-modal>` save-success dialog.
- `app/Http/Controllers/SurveyController.php` — `qolIndex()` gains a search clause; `qolCreate()` resolves an existing draft into the form.
- `resources/views/surveys/qol/index.blade.php` — add search input to filter form; give draft rows a "Continue Draft" action.
- `app/Http/Controllers/SeniorCitizenController.php` — `show()` passes a `$draftSurvey`.
- `resources/views/seniors/show.blade.php` — header + history CTAs become "Continue draft" when a draft exists.
- `resources/views/livewire/surveys/qol-survey-form.blade.php` — strengthen rating-button checked state (lines 213-220).
- `tests/Feature/Batch1RegistrationSurveysTest.php` — new feature test covering save modal, draft routing, search, and the record-page CTA.

---

## Task 1: Profile-save success modal

Replace the inline success banner with a modal that forces an explicit choice.

**Files:**
- Modify: `resources/views/livewire/surveys/profile-survey.blade.php:36-44`
- Test: `tests/Feature/Batch1RegistrationSurveysTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Batch1RegistrationSurveysTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\Surveys\ProfileSurvey;
use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Batch1RegistrationSurveysTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'encoder', 'viewer'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin@osca.local'],
            ['name' => 'OSCA Admin', 'password' => Hash::make('password')]
        );
        $this->admin->syncRoles(['admin']);
    }

    private function makeSenior(array $overrides = []): SeniorCitizen
    {
        return SeniorCitizen::create(array_merge([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
        ], $overrides));
    }

    #[Test]
    public function saving_a_profile_shows_the_success_modal_with_osca_id_and_survey_link(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ProfileSurvey::class)
            ->set('firstName', 'Maria')
            ->set('lastName', 'Santos')
            ->set('barangay', 'Anibong')
            ->set('dateOfBirth', '1948-05-02')
            ->call('save')
            ->assertSet('saved', true)
            ->assertSee('Profile Saved')
            ->assertSee('ANI-')                       // generated OSCA ID prefix
            ->assertSee('Take QoL Survey');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter saving_a_profile_shows_the_success_modal_with_osca_id_and_survey_link`
Expected: FAIL — current view renders "Profile saved" (lowercase, in `<x-alert>`), not "Profile Saved" heading.

- [ ] **Step 3: Replace the banner with a modal**

In `resources/views/livewire/surveys/profile-survey.blade.php`, replace lines 35-44 (the `{{-- Success banner --}}` block) with:

```blade
    {{-- Success modal — forces an explicit next action --}}
    @if ($saved && $senior)
    <div x-data="{ open: true }">
        <x-modal show="open" max-width="max-w-md" :closeable="false">
            <div class="text-center">
                <div class="w-12 h-12 rounded-2xl grid place-items-center mx-auto mb-3
                            bg-forest-50 dark:bg-forest-900/40 text-forest-700 dark:text-forest-300">
                    <x-heroicon-o-check-circle class="w-7 h-7" aria-hidden="true" />
                </div>
                <h2 class="font-display text-xl text-ink-900 dark:text-[#e4e1d8] mb-1">Profile Saved</h2>
                <p class="text-[12.5px] text-ink-500 dark:text-[#8a9087] mb-1">OSCA ID</p>
                <p class="font-mono text-lg font-bold tracking-wide text-forest-700 dark:text-forest-300 mb-5">
                    {{ $senior->osca_id }}
                </p>
                <div class="flex flex-col gap-2">
                    <a href="{{ route('surveys.qol.create', $senior) }}" class="btn btn-primary justify-center">
                        + Take QoL Survey →
                    </a>
                    <a href="{{ route('seniors.show', $senior) }}" class="btn btn-secondary justify-center">
                        ← Back to Profile
                    </a>
                </div>
            </div>
        </x-modal>
    </div>
    @endif
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter saving_a_profile_shows_the_success_modal_with_osca_id_and_survey_link`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/surveys/profile-survey.blade.php tests/Feature/Batch1RegistrationSurveysTest.php
git commit -m "feat(surveys): profile-save success modal with OSCA ID and survey CTA"
```

---

## Task 2: Resume existing draft instead of creating a duplicate

`qolCreate()` currently always opens a blank form. If the senior already has a draft,
opening "new survey" and submitting creates a second record and strands the draft.
Fix: `qolCreate()` resolves an existing draft and loads it into the form.

**Files:**
- Modify: `app/Http/Controllers/SurveyController.php` (`qolCreate`, lines ~30-33)
- Test: `tests/Feature/Batch1RegistrationSurveysTest.php`

- [ ] **Step 1: Write the failing test**

Append to `Batch1RegistrationSurveysTest`:

```php
    #[Test]
    public function opening_new_survey_for_a_senior_with_a_draft_resumes_that_draft(): void
    {
        $senior = $this->makeSenior();
        $draft = QolSurvey::create([
            'senior_citizen_id' => $senior->id,
            'survey_date' => now()->format('Y-m-d'),
            'status' => 'draft',
            'a1_enjoy_life' => 3,
        ]);

        $this->actingAs($this->admin)
            ->get(route('surveys.qol.create', $senior))
            ->assertOk()
            ->assertViewHas('surveyId', $draft->id);
    }

    #[Test]
    public function opening_new_survey_for_a_senior_without_a_draft_starts_blank(): void
    {
        $senior = $this->makeSenior();

        $this->actingAs($this->admin)
            ->get(route('surveys.qol.create', $senior))
            ->assertOk()
            ->assertViewHas('surveyId', null);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter opening_new_survey_for_a_senior_with_a_draft_resumes_that_draft`
Expected: FAIL — `qolCreate` does not pass `surveyId` to the view.

- [ ] **Step 3: Resolve the draft in `qolCreate`**

In `app/Http/Controllers/SurveyController.php`, replace the `qolCreate` method:

```php
    public function qolCreate(SeniorCitizen $senior)
    {
        $draft = $senior->qolSurveys()->where('status', 'draft')->latest()->first();

        return view('surveys.qol.create', [
            'senior' => $senior,
            'surveyId' => $draft?->id,
        ]);
    }
```

(`surveys/qol/create.blade.php` already passes `:survey-id="$surveyId ?? null"` to the
Livewire component, which repopulates via `populateFromSurvey()`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter opening_new_survey_for_a_senior`
Expected: PASS (both draft-resume and blank-start tests)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/SurveyController.php tests/Feature/Batch1RegistrationSurveysTest.php
git commit -m "fix(surveys): resume existing draft instead of creating a duplicate survey"
```

---

## Task 3: "Continue Draft" action in the QoL Surveys list

**Files:**
- Modify: `resources/views/surveys/qol/index.blade.php:84-91` (Actions cell)

- [ ] **Step 1: Update the Actions cell for draft rows**

In `resources/views/surveys/qol/index.blade.php`, replace the actions block (the
`<td class="td">` … Results/Edit links, lines ~84-91) so draft rows get a prominent
"Continue Draft" button and non-drafts keep Edit/Results:

```blade
                    <td class="td">
                        <div class="flex justify-center gap-1.5">
                            @if ($survey->status === 'draft')
                            <a href="{{ route('surveys.qol.edit', $survey) }}"
                               class="btn btn-primary text-[11.5px] px-2.5 py-1">Continue Draft →</a>
                            @else
                                @if ($survey->status === 'processed')
                                <a href="{{ route('surveys.qol.results', $survey) }}"
                                   class="btn btn-ghost text-[11.5px] px-2 py-1">Results</a>
                                @endif
                                <a href="{{ route('surveys.qol.edit', $survey) }}"
                                   class="btn btn-ghost text-[11.5px] px-2 py-1">Edit</a>
                            @endif
```

Leave the existing Delete `<div x-data>` block (lines 92-109) exactly as-is,
immediately after this — it stays available for all rows.

- [ ] **Step 2: Verify rendering manually**

Run: `php artisan test --filter Batch1RegistrationSurveys` (sanity — no regressions)
Expected: PASS. Then load `/surveys/qol?status=draft` in the browser: draft rows show a
solid "Continue Draft →" button; submitted/processed rows show ghost Edit (+ Results).

- [ ] **Step 3: Commit**

```bash
git add resources/views/surveys/qol/index.blade.php
git commit -m "feat(surveys): prominent Continue Draft action on draft survey rows"
```

---

## Task 4: "Continue draft" CTA on the senior record page

**Files:**
- Modify: `app/Http/Controllers/SeniorCitizenController.php` (`show`)
- Modify: `resources/views/seniors/show.blade.php:26` (header) and `:560` (history card)
- Test: `tests/Feature/Batch1RegistrationSurveysTest.php`

- [ ] **Step 1: Write the failing test**

Append to `Batch1RegistrationSurveysTest`:

```php
    #[Test]
    public function senior_record_shows_continue_draft_when_a_draft_exists(): void
    {
        $senior = $this->makeSenior();
        $draft = QolSurvey::create([
            'senior_citizen_id' => $senior->id,
            'survey_date' => now()->format('Y-m-d'),
            'status' => 'draft',
        ]);

        $this->actingAs($this->admin)
            ->get(route('seniors.show', $senior))
            ->assertOk()
            ->assertSee('Continue draft')
            ->assertSee(route('surveys.qol.edit', $draft), false);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter senior_record_shows_continue_draft_when_a_draft_exists`
Expected: FAIL — view has no "Continue draft" text.

- [ ] **Step 3: Pass the draft from the controller**

In `app/Http/Controllers/SeniorCitizenController.php`, update `show()`:

```php
    public function show(SeniorCitizen $senior)
    {
        $senior->load([
            'qolSurveys' => fn ($q) => $q->latest()->limit(5),
            'latestMlResult.recommendations',
            'mlResults' => fn ($q) => $q->latest()->limit(3),
        ]);

        $draftSurvey = $senior->qolSurveys()->where('status', 'draft')->latest()->first();

        return view('seniors.show', compact('senior', 'draftSurvey'));
    }
```

- [ ] **Step 4: Update the header CTA (line ~26)**

In `resources/views/seniors/show.blade.php`, replace the "New QoL Survey" anchor at
line 26 with a conditional:

```blade
            @if (!empty($draftSurvey))
            <a href="{{ route('surveys.qol.edit', $draftSurvey) }}" class="btn btn-primary">
                <x-heroicon-o-clipboard-document-list class="w-3.5 h-3.5" /> Continue draft
            </a>
            @else
            <a href="{{ route('surveys.qol.create', $senior) }}" class="btn">
                <x-heroicon-o-clipboard-document-list class="w-3.5 h-3.5" /> New QoL Survey
            </a>
            @endif
```

- [ ] **Step 5: Update the history-card link (line ~560)**

In the same file, replace the "+ New survey" link at line 560 with:

```blade
                    @if (!empty($draftSurvey))
                    <a href="{{ route('surveys.qol.edit', $draftSurvey) }}" class="text-xs text-forest-700 font-semibold hover:text-forest-900">Continue draft →</a>
                    @else
                    <a href="{{ route('surveys.qol.create', $senior) }}" class="text-xs text-forest-700 font-semibold hover:text-forest-900">+ New survey</a>
                    @endif
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter senior_record_shows_continue_draft_when_a_draft_exists`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/SeniorCitizenController.php resources/views/seniors/show.blade.php tests/Feature/Batch1RegistrationSurveysTest.php
git commit -m "feat(seniors): surface Continue draft CTA on record page when a draft exists"
```

---

## Task 5: QoL Surveys search (name + OSCA ID)

**Files:**
- Modify: `app/Http/Controllers/SurveyController.php` (`qolIndex`)
- Modify: `resources/views/surveys/qol/index.blade.php` (filter form + Clear condition)
- Test: `tests/Feature/Batch1RegistrationSurveysTest.php`

- [ ] **Step 1: Write the failing test**

Append to `Batch1RegistrationSurveysTest`:

```php
    #[Test]
    public function qol_index_search_matches_senior_name_and_osca_id(): void
    {
        $alice = $this->makeSenior(['first_name' => 'Alice', 'last_name' => 'Reyes']);
        $bob = $this->makeSenior(['first_name' => 'Bob', 'last_name' => 'Tan']);
        foreach ([$alice, $bob] as $s) {
            QolSurvey::create([
                'senior_citizen_id' => $s->id,
                'survey_date' => now()->format('Y-m-d'),
                'status' => 'submitted',
            ]);
        }

        // Search by name
        $this->actingAs($this->admin)
            ->get(route('surveys.qol.index', ['search' => 'Alice']))
            ->assertOk()
            ->assertSee('Alice Reyes')
            ->assertDontSee('Bob Tan');

        // Search by OSCA ID
        $this->actingAs($this->admin)
            ->get(route('surveys.qol.index', ['search' => $bob->osca_id]))
            ->assertOk()
            ->assertSee('Bob Tan')
            ->assertDontSee('Alice Reyes');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter qol_index_search_matches_senior_name_and_osca_id`
Expected: FAIL — `search` is ignored; both names appear.

- [ ] **Step 3: Add the search clause to `qolIndex`**

In `app/Http/Controllers/SurveyController.php`, update `qolIndex()`:

```php
    public function qolIndex(Request $request)
    {
        $surveys = QolSurvey::with(['seniorCitizen'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->barangay, fn ($q) => $q->whereHas('seniorCitizen', fn ($q) => $q->where('barangay', $request->barangay))
            )
            ->when($request->search, fn ($q, $term) => $q->whereHas('seniorCitizen', fn ($q) => $q
                ->where('osca_id', 'like', "%{$term}%")
                ->orWhereRaw("LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?", ['%'.strtolower($term).'%'])
            ))
            ->latest('survey_date')
            ->paginate(20)->withQueryString();

        return view('surveys.qol.index', compact('surveys'));
    }
```

- [ ] **Step 4: Add the search input to the filter form**

In `resources/views/surveys/qol/index.blade.php`, inside the filter `card-body`
(after the Status block, before the Barangay block, around line 22), add:

```blade
            <div class="min-w-[200px] flex-1">
                <label class="eyebrow block mb-1.5">Search</label>
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Name or OSCA ID…" class="form-input w-full">
            </div>
```

Then update the Clear-button condition (line ~36) to include `search`:

```blade
                @if (request()->hasAny(['status','barangay','search']))
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter qol_index_search_matches_senior_name_and_osca_id`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/SurveyController.php resources/views/surveys/qol/index.blade.php tests/Feature/Batch1RegistrationSurveysTest.php
git commit -m "feat(surveys): search QoL surveys by senior name or OSCA ID"
```

---

## Task 6: Rating-button active state (dark-mode feedback)

CSS-only change; no automated test (visual). Strengthen the checked state so the
selected number is unmistakable in both themes.

**Files:**
- Modify: `resources/views/livewire/surveys/qol-survey-form.blade.php:213-218`

- [ ] **Step 1: Strengthen the checked state**

In `resources/views/livewire/surveys/qol-survey-form.blade.php`, replace the inner
`<div>` of the rating label (lines 213-218) with:

```blade
                            <div class="text-center py-2 rounded-xl border-2 text-[13px] font-semibold transition-all
                                border-paper-rule dark:border-[#2b3530] text-ink-500 dark:text-[#6b7570]
                                peer-checked:border-forest-500 peer-checked:bg-forest-600 peer-checked:text-white
                                peer-checked:ring-2 peer-checked:ring-forest-400 peer-checked:ring-offset-2
                                peer-checked:ring-offset-paper dark:peer-checked:ring-offset-[#1a221e]
                                peer-checked:shadow-md peer-checked:scale-[1.04]
                                hover:border-forest-300 dark:hover:border-forest-600 hover:bg-forest-50 dark:hover:bg-forest-900/20 hover:text-forest-700 dark:hover:text-forest-400">
                                {{ $val }}
                            </div>
```

- [ ] **Step 2: Build assets and verify in both themes**

Run: `npm run build`
Expected: build succeeds. Then open a QoL survey form, toggle dark mode, and click
rating numbers: the selected number shows a filled green chip with a visible ring and
slight lift — clearly distinct from unselected in both light and dark mode.

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/surveys/qol-survey-form.blade.php
git commit -m "fix(surveys): high-contrast active state for QoL rating buttons in dark mode"
```

---

## Task 7: Full-suite verification

- [ ] **Step 1: Run the batch test file**

Run: `php artisan test --filter Batch1RegistrationSurveys`
Expected: PASS (all assertions across Tasks 1-5).

- [ ] **Step 2: Run the full feature suite for regressions**

Run: `php artisan test`
Expected: PASS (no regressions in existing tests).

- [ ] **Step 3: Manual smoke checklist (from the spec)**

- Save a new profile → modal appears with correct OSCA ID; both buttons navigate; backdrop does not dismiss.
- Save a draft, return via QoL list "Continue Draft" → form repopulates.
- Open a senior with a draft → "Continue draft" CTA present; submitting updates the draft (no duplicate).
- QoL list search by partial name and by OSCA ID; combine with status/barangay; pagination keeps the query.
- Dark mode: selecting a rating number shows an unmistakable active state.

---

## Self-Review Notes

- **Spec coverage:** Req 1 → Task 1; Req 2 (draft openable + non-stranded) → Tasks 2/3/4; Req 3 (search) → Task 5; Req 4 (rating button) → Task 6. All covered.
- **Type/route consistency:** `surveys.qol.create`, `surveys.qol.edit`, `seniors.show` routes used consistently; `$draftSurvey` defined in controller (Task 4) before use in view; `surveyId` view var matches `create.blade.php`'s existing `:survey-id="$surveyId ?? null"`.
- **No placeholders:** every code/edit step shows concrete content.
