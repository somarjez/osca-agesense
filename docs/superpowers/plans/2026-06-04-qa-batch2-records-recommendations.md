# QA Batch 2 — Records & Recommendations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the individual senior record's ML Analysis labels, redesign the Export PDF as an official government document, eliminate duplicate recommendations by scoping to each senior's latest ML result, and add search to the Recommendations index.

**Architecture:** Laravel 11 + Livewire 3 + Tailwind (forest/paper/ink tokens), DomPDF for PDF export. The duplicate-recs fix is expressed once as a `Recommendation::scopeCurrent()` correlated subquery (recs whose `ml_result_id` is the max ml_result id for their senior), reused by a `SeniorCitizen::currentRecommendations()` relationship, the controller queries, and the index stats. Tests are PHPUnit feature tests using `DatabaseTransactions` and the already-seeded test database.

**Tech Stack:** PHP 8.2, Laravel, Eloquent, DomPDF (barryvdh/laravel-dompdf), Blade, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-06-04-qa-batch2-records-recommendations-design.md`

---

## File Structure

- `resources/views/seniors/show.blade.php` — ML Analysis strip + XAI domain labels + risk caption (Task 1).
- `app/Models/Recommendation.php` — new `scopeCurrent()` (Task 2).
- `app/Models/SeniorCitizen.php` — new `currentRecommendations()` relationship (Task 2).
- `app/Http/Controllers/RecommendationController.php` — `show()`/`index()` use current scoping; add search (Tasks 3, 4).
- `resources/views/recommendations/index.blade.php` — search input + Clear condition (Task 4).
- `resources/views/seniors/pdf.blade.php` — official-document redesign (Task 5).
- `tests/Feature/Batch2RecordsRecommendationsTest.php` — new feature tests (Tasks 1-5).

---

## Task 1: ML Analysis label fixes (`seniors/show.blade.php`)

Standardize domain terminology and clarify the risk badge. Blade-only.

**Files:**
- Modify: `resources/views/seniors/show.blade.php` (`:202-206`, `:185-186`, `:256-260`)
- Test: `tests/Feature/Batch2RecordsRecommendationsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Batch2RecordsRecommendationsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\MlResult;
use App\Models\QolSurvey;
use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Batch2RecordsRecommendationsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

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

    /** Create an ML result (and its backing survey) for a senior. Returns the MlResult. */
    private function makeMlResult(SeniorCitizen $senior, array $overrides = []): MlResult
    {
        $survey = QolSurvey::create([
            'senior_citizen_id' => $senior->id,
            'survey_date' => now()->format('Y-m-d'),
            'status' => 'processed',
        ]);

        return MlResult::create(array_merge([
            'senior_citizen_id' => $senior->id,
            'qol_survey_id' => $survey->id,
            'model_version' => '2.0.0',
            'prediction_source' => 'live',
            'overall_risk_level' => 'HIGH',
            'ic_risk' => 0.6, 'env_risk' => 0.5, 'func_risk' => 0.7,
            'wellbeing_score' => 0.41,
            'cluster_named_id' => 'G4',
            'cluster_name' => 'Low Functioning / Multi-Domain Priority Seniors',
            'scored_at' => now(),
            'processed_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function ml_analysis_strip_uses_who_domain_labels_and_risk_caption(): void
    {
        $senior = $this->makeSenior();
        $this->makeMlResult($senior);

        $this->actingAs($this->admin)
            ->get(route('seniors.show', $senior))
            ->assertOk()
            ->assertSee('Intrinsic Capacity')
            ->assertSee('Functional Ability')
            ->assertSee('Overall risk');
    }
}
```

- [ ] **Step 2: Run test, verify it FAILS**

Run: `php artisan test --filter ml_analysis_strip_uses_who_domain_labels_and_risk_caption`
Expected: FAIL — the strip currently renders "Physical"/"Functioning" and no "Overall risk" caption.

- [ ] **Step 3: Relabel the assessment-strip domain bars**

In `resources/views/seniors/show.blade.php`, replace the domain-bars array (`:202-206`):
```blade
                @foreach ([
                    ['Physical', $ml->ic_risk],
                    ['Environment', $ml->env_risk],
                    ['Functioning', $ml->func_risk],
                ] as [$label, $score])
```
with:
```blade
                @foreach ([
                    ['Intrinsic Capacity', $ml->ic_risk],
                    ['Environment', $ml->env_risk],
                    ['Functional Ability', $ml->func_risk],
                ] as [$label, $score])
```

- [ ] **Step 4: Add an "Overall risk" caption to the risk badge**

In the same file, the "Risk + Cluster" block (`:185-186`) currently is:
```blade
            <div class="flex items-center gap-2.5 flex-shrink-0">
                <x-risk-badge :level="$ml->overall_risk_level" />
```
Replace those two lines with:
```blade
            <div class="flex items-center gap-2.5 flex-shrink-0">
                <span class="text-[10.5px] uppercase tracking-wide text-ink-400 dark:text-[#6b7570] font-semibold">Overall risk</span>
                <x-risk-badge :level="$ml->overall_risk_level" />
```

- [ ] **Step 5: Align the XAI domain labels**

In the same file, the XAI domains array (`:256-260`):
```blade
        $xaiDomains = [
            'ic'   => ['label' => 'Physical Capacity',   'risk' => $ml->ic_risk_level],
            'env'  => ['label' => 'Environment',          'risk' => $ml->env_risk_level],
            'func' => ['label' => 'Daily Functioning',    'risk' => $ml->func_risk_level],
        ];
```
Replace with:
```blade
        $xaiDomains = [
            'ic'   => ['label' => 'Intrinsic Capacity',  'risk' => $ml->ic_risk_level],
            'env'  => ['label' => 'Environment',          'risk' => $ml->env_risk_level],
            'func' => ['label' => 'Functional Ability',   'risk' => $ml->func_risk_level],
        ];
```

- [ ] **Step 6: Run test, verify it PASSES**

Run: `php artisan test --filter ml_analysis_strip_uses_who_domain_labels_and_risk_caption`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add resources/views/seniors/show.blade.php tests/Feature/Batch2RecordsRecommendationsTest.php
git commit -m "fix(seniors): WHO domain labels + Overall risk caption on ML analysis strip"
```

---

## Task 2: `scopeCurrent` + `currentRecommendations` relationship

The reusable "latest assessment only" scoping. A recommendation is *current* iff its
`ml_result_id` equals the max ml_result id for its senior.

**Files:**
- Modify: `app/Models/Recommendation.php`
- Modify: `app/Models/SeniorCitizen.php`
- Test: `tests/Feature/Batch2RecordsRecommendationsTest.php`

- [ ] **Step 1: Write the failing test**

Append to `Batch2RecordsRecommendationsTest`:

```php
    /** Attach a recommendation to a given ML result. */
    private function makeRec(MlResult $ml, array $overrides = []): Recommendation
    {
        return Recommendation::create(array_merge([
            'ml_result_id' => $ml->id,
            'senior_citizen_id' => $ml->senior_citizen_id,
            'priority' => 1,
            'type' => 'referral',
            'domain' => 'medical',
            'action' => 'Default action',
            'urgency' => 'planned',
            'status' => 'pending',
        ], $overrides));
    }

    #[Test]
    public function current_recommendations_returns_only_latest_ml_results_recs(): void
    {
        $senior = $this->makeSenior();

        $oldMl = $this->makeMlResult($senior);
        $this->makeRec($oldMl, ['action' => 'OLD recommendation']);

        $newMl = $this->makeMlResult($senior);
        $this->makeRec($newMl, ['action' => 'NEW recommendation A']);
        $this->makeRec($newMl, ['action' => 'NEW recommendation B']);

        $current = $senior->currentRecommendations()->get();

        $this->assertCount(2, $current);
        $this->assertEqualsCanonicalizing(
            ['NEW recommendation A', 'NEW recommendation B'],
            $current->pluck('action')->all()
        );
    }
```

- [ ] **Step 2: Run test, verify it FAILS**

Run: `php artisan test --filter current_recommendations_returns_only_latest_ml_results_recs`
Expected: FAIL — `currentRecommendations` does not exist (BadMethodCallException).

- [ ] **Step 3: Add `scopeCurrent` to the Recommendation model**

In `app/Models/Recommendation.php`, after the existing `scopePending` method, add:

```php
    /**
     * Limit to "current" recommendations — those belonging to each senior's
     * latest ML result (max ml_result id per senior). Older assessments' recs
     * remain in the table as history but are excluded here. Correlated subquery
     * so it composes inside relationships, whereHas, withCount, and aggregates.
     */
    public function scopeCurrent($query)
    {
        return $query->whereRaw(
            'recommendations.ml_result_id = (select max(id) from ml_results where ml_results.senior_citizen_id = recommendations.senior_citizen_id)'
        );
    }
```

- [ ] **Step 4: Add `currentRecommendations` to SeniorCitizen**

In `app/Models/SeniorCitizen.php`, immediately after the existing `recommendations()` method (`:112-115`), add:

```php
    /**
     * Recommendations from the senior's latest ML result only (current assessment).
     * Reuses Recommendation::scopeCurrent so the "latest" definition lives in one place.
     */
    public function currentRecommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class)->current();
    }
```

Confirm `Illuminate\Database\Eloquent\Relations\HasMany` is already imported in the file (the existing `recommendations()` returns `HasMany`, so it is).

- [ ] **Step 5: Run test, verify it PASSES**

Run: `php artisan test --filter current_recommendations_returns_only_latest_ml_results_recs`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Models/Recommendation.php app/Models/SeniorCitizen.php tests/Feature/Batch2RecordsRecommendationsTest.php
git commit -m "feat(recommendations): scopeCurrent + currentRecommendations (latest assessment only)"
```

---

## Task 3: Scope RecommendationController to current recommendations

Wire the dedup fix into the controller's `show()` and `index()`.

**Files:**
- Modify: `app/Http/Controllers/RecommendationController.php`
- Test: `tests/Feature/Batch2RecordsRecommendationsTest.php`

- [ ] **Step 1: Write the failing test**

Append to `Batch2RecordsRecommendationsTest`:

```php
    #[Test]
    public function recommendations_show_page_displays_only_current_recs(): void
    {
        $senior = $this->makeSenior();
        $oldMl = $this->makeMlResult($senior);
        $this->makeRec($oldMl, ['action' => 'STALE old action']);
        $newMl = $this->makeMlResult($senior);
        $this->makeRec($newMl, ['action' => 'FRESH current action']);

        $this->actingAs($this->admin)
            ->get(route('recommendations.show', $senior))
            ->assertOk()
            ->assertSee('FRESH current action')
            ->assertDontSee('STALE old action');
    }

    #[Test]
    public function recommendations_index_stats_count_only_current_recs(): void
    {
        $senior = $this->makeSenior();
        $oldMl = $this->makeMlResult($senior);
        $this->makeRec($oldMl, ['action' => 'old1']);
        $this->makeRec($oldMl, ['action' => 'old2']);
        $newMl = $this->makeMlResult($senior);
        $this->makeRec($newMl, ['action' => 'new1']);

        $response = $this->actingAs($this->admin)
            ->get(route('recommendations.index'))
            ->assertOk();

        // This senior contributes exactly 1 current rec, not 3.
        $stats = $response->viewData('stats');
        $this->assertSame(1, \App\Models\Recommendation::current()
            ->where('senior_citizen_id', $senior->id)->count());
        // The senior row's recommendations_count reflects current only.
        $row = $response->viewData('seniors')->firstWhere('id', $senior->id);
        $this->assertSame(1, (int) $row->recommendations_count);
    }
```

- [ ] **Step 2: Run tests, verify they FAIL**

Run: `php artisan test --filter recommendations_`
Expected: FAIL — show displays both recs; index `recommendations_count` is 3.

- [ ] **Step 3: Scope `show()` to current recommendations**

In `app/Http/Controllers/RecommendationController.php`, replace `show()`:
```php
    public function show(SeniorCitizen $senior)
    {
        $recommendations = $senior->currentRecommendations()
            ->with('mlResult')
            ->orderBy('priority')
            ->get();

        return view('recommendations.show', compact('senior', 'recommendations'));
    }
```

- [ ] **Step 4: Scope `index()` stats, counts, and filters to current**

In the same file, replace the `$stats` array and the `$seniors` query in `index()`. The new `$stats`:
```php
        $stats = [
            'total' => Recommendation::current()->count(),
            'pending' => Recommendation::current()->where('status', 'pending')->count(),
            'immediate' => Recommendation::current()->where('urgency', 'immediate')->where('status', 'pending')->count(),
            'seniors' => SeniorCitizen::active()->whereHas('currentRecommendations')->count(),
        ];
```
And the `$seniors` query — change `whereHas('recommendations')` to `whereHas('currentRecommendations')`, the three `withCount` keys from `recommendations` to `currentRecommendations`, and the `has_urgent` `whereHas('recommendations', …)` to `whereHas('currentRecommendations', …)`:
```php
        $seniors = SeniorCitizen::active()
            ->whereHas('currentRecommendations')
            ->withCount([
                'currentRecommendations as recommendations_count',
                'currentRecommendations as pending_count' => fn ($q) => $q->where('status', 'pending'),
                'currentRecommendations as immediate_count' => fn ($q) => $q->whereIn('urgency', ['immediate', 'urgent'])->where('status', 'pending'),
            ])
            ->with(['latestMlResult'])
            ->when($request->barangay, fn ($q) => $q->where('barangay', $request->barangay))
            ->when($request->risk, fn ($q) => $q->byRiskLevel($request->risk))
            ->when($request->has_urgent, fn ($q) => $q->whereHas('currentRecommendations', fn ($r) => $r->whereIn('urgency', ['immediate', 'urgent'])->where('status', 'pending')
            )
            )
            ->orderByDesc('immediate_count')
            ->orderByDesc('pending_count')
            ->paginate(20)
            ->withQueryString();
```
Leave the `$barangays` line and the `return view(...)` unchanged. Confirm `Recommendation` is already imported at the top of the controller (it is).

- [ ] **Step 5: Run tests, verify they PASS**

Run: `php artisan test --filter recommendations_`
Expected: PASS (show + index stats tests)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/RecommendationController.php tests/Feature/Batch2RecordsRecommendationsTest.php
git commit -m "fix(recommendations): scope show + index to current assessment (no duplicates)"
```

---

## Task 4: Recommendations index search (name + OSCA ID)

**Files:**
- Modify: `app/Http/Controllers/RecommendationController.php` (`index`)
- Modify: `resources/views/recommendations/index.blade.php`
- Test: `tests/Feature/Batch2RecordsRecommendationsTest.php`

- [ ] **Step 1: Write the failing test**

Append to `Batch2RecordsRecommendationsTest`:

```php
    #[Test]
    public function recommendations_index_search_matches_name_and_osca_id(): void
    {
        $alice = $this->makeSenior(['first_name' => 'Alicia', 'last_name' => 'Reyes']);
        $bob = $this->makeSenior(['first_name' => 'Roberto', 'last_name' => 'Tan']);
        foreach ([$alice, $bob] as $s) {
            $this->makeRec($this->makeMlResult($s));
        }

        $this->actingAs($this->admin)
            ->get(route('recommendations.index', ['search' => 'Alicia']))
            ->assertOk()
            ->assertSee('Alicia Reyes')
            ->assertDontSee('Roberto Tan');

        $this->actingAs($this->admin)
            ->get(route('recommendations.index', ['search' => $bob->osca_id]))
            ->assertOk()
            ->assertSee('Roberto Tan')
            ->assertDontSee('Alicia Reyes');
    }
```

- [ ] **Step 2: Run test, verify it FAILS**

Run: `php artisan test --filter recommendations_index_search_matches_name_and_osca_id`
Expected: FAIL — search is ignored; both names appear.

- [ ] **Step 3: Add the search clause to `index()`**

In `app/Http/Controllers/RecommendationController.php`, in the `$seniors` query, add a `search` clause after the `has_urgent` `when(...)` (before `orderByDesc`):
```php
            ->when($request->search, fn ($q, $term) => $q
                ->where('osca_id', 'like', "%{$term}%")
                ->orWhereRaw("LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?", ['%'.strtolower($term).'%'])
            )
```
Note: this composes with the preceding `whereHas('currentRecommendations')`. Because the search uses `orWhere*`, wrap it so the OR does not leak past the current-recs constraint. Use a grouped closure exactly as written above (the `fn ($q, $term) => $q->where(...)->orWhereRaw(...)` body is itself the group passed to `when`, which Laravel wraps in parentheses). This keeps it as `… AND (osca_id LIKE ? OR name LIKE ?)`.

- [ ] **Step 4: Add the search input to the filter form**

In `resources/views/recommendations/index.blade.php`, inside the filter `<form method="GET">` (before the Barangay `<select>` block around `:25`), add:
```blade
            <div class="min-w-[200px] flex-1">
                <label class="eyebrow block mb-1.5">Search</label>
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Name or OSCA ID…" class="form-input w-full">
            </div>
```
Match the surrounding markup: if the existing filter fields are wrapped differently (e.g. each in a labeled column div), mirror that wrapper. READ the file first to match the exact structure. Then update the Clear-button condition (`:52`):
```blade
                @if (request()->hasAny(['barangay','risk','has_urgent','search']))
```

- [ ] **Step 5: Run test, verify it PASSES**

Run: `php artisan test --filter recommendations_index_search_matches_name_and_osca_id`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/RecommendationController.php resources/views/recommendations/index.blade.php tests/Feature/Batch2RecordsRecommendationsTest.php
git commit -m "feat(recommendations): search index by senior name or OSCA ID"
```

---

## Task 5: Official-document PDF redesign (`seniors/pdf.blade.php`)

Rebuild the export as a formal government document. **DomPDF constraint:** use
table-based layout (no flexbox/grid), inline-safe CSS, forest-only palette. The same
template serves both "Export PDF" (download) and printing ("Ctrl+P") of that PDF.

**Files:**
- Modify: `resources/views/seniors/pdf.blade.php`
- Test: `tests/Feature/Batch2RecordsRecommendationsTest.php`

- [ ] **Step 1: Write the failing test**

Append to `Batch2RecordsRecommendationsTest`:

```php
    #[Test]
    public function exported_pdf_template_is_formal_and_uses_corrected_labels(): void
    {
        $senior = $this->makeSenior();
        $this->makeMlResult($senior);

        $html = view('seniors.pdf', ['senior' => $senior->fresh()])->render();

        // Formal document elements
        $this->assertStringContainsString('Office', $html);            // letterhead org line
        $this->assertStringContainsString('Prepared by', $html);       // signature block
        $this->assertStringContainsString('Generated on', $html);      // footer timestamp
        // Corrected WHO labels
        $this->assertStringContainsString('Intrinsic Capacity', $html);
        $this->assertStringContainsString('Functional Ability', $html);
        // No leftover teal palette
        $this->assertStringNotContainsString('#0f766e', $html);
        $this->assertStringNotContainsString('#f0fdfa', $html);
        $this->assertStringNotContainsString('#134e4a', $html);
    }
```

- [ ] **Step 2: Run test, verify it FAILS**

Run: `php artisan test --filter exported_pdf_template_is_formal_and_uses_corrected_labels`
Expected: FAIL — current template has teal hex, no signature/"Generated on" block, old labels.

- [ ] **Step 3: Replace the `<style>` palette with a formal forest palette**

In `resources/views/seniors/pdf.blade.php`, within `<style>`, make these exact replacements (use Grep to find each):
- `.senior-name-block { background: #f0fdfa; border: 1px solid #99f6e4; ... }` → `.senior-name-block { background: #f1f5f2; border: 1px solid #cfe0d6; border-radius: 6px; padding: 12px 16px; margin-bottom: 16px; display: table; width: 100%; }`
- `.senior-name { ... color: #134e4a; }` → change color to `#1b3a2b`
- `.senior-meta { ... color: #0f766e; }` → change color to `#2d6a4f`
- `.tag-teal   { background: #ccfbf1; color: #0f766e; }` → `.tag-teal   { background: #e3efe8; color: #2d6a4f; }`
- Any remaining `#0f766e` / `#134e4a` / `#f0fdfa` / `#99f6e4` / `#ccfbf1` occurrences → forest equivalents (`#2d6a4f`, `#1b3a2b`, `#f1f5f2`, `#cfe0d6`, `#e3efe8`).

Then add these formal-document style rules at the end of the `<style>` block (before `</style>`):
```css
/* Formal letterhead */
.letterhead { display: table; width: 100%; border-bottom: 2px solid #2d6a4f; padding-bottom: 12px; margin-bottom: 6px; }
.letterhead .lh-left { display: table-cell; vertical-align: middle; width: 70%; }
.letterhead .lh-right { display: table-cell; vertical-align: middle; text-align: right; width: 30%; }
.letterhead .lh-org { font-size: 14px; font-weight: bold; color: #2d6a4f; letter-spacing: 0.02em; }
.letterhead .lh-sub { font-size: 9.5px; color: #475569; margin-top: 2px; }
.letterhead .lh-title { font-size: 13px; font-weight: bold; color: #1e293b; }
.letterhead .lh-meta { font-size: 8.5px; color: #94a3b8; margin-top: 2px; }
.confidential { font-size: 8px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 14px; }
/* Signature + footer */
.signatures { display: table; width: 100%; margin-top: 28px; }
.signatures .sig { display: table-cell; width: 50%; vertical-align: bottom; padding-right: 24px; }
.signatures .sig:last-child { padding-right: 0; padding-left: 24px; }
.sig-line { border-top: 1px solid #475569; margin-top: 32px; padding-top: 4px; font-size: 9px; color: #475569; }
.doc-footer { margin-top: 18px; border-top: 1px solid #e2e8f0; padding-top: 6px; font-size: 8px; color: #94a3b8; text-align: center; }
```

- [ ] **Step 4: Replace the header markup with a formal letterhead**

Find the existing header block (`:89-105` area — the `<div class="header">` containing `org-name`, `org-sub`, `report-label`, OSCA ID). Replace that header `<div>` with:
```blade
    <div class="letterhead">
        <div class="lh-left">
            <div class="lh-org">Office for Senior Citizens Affairs (OSCA)</div>
            <div class="lh-sub">Senior Citizens Welfare and Records Management</div>
        </div>
        <div class="lh-right">
            <div class="lh-title">Senior Citizen Profile Report</div>
            <div class="lh-meta">OSCA ID: {{ $senior->osca_id }}</div>
            <div class="lh-meta">Generated on {{ now()->format('F j, Y') }}</div>
        </div>
    </div>
    <div class="confidential">Confidential — for official OSCA use only</div>
```
Keep the existing `.senior-name-block` (now restyled) below this. If the OSCA ID was only shown in the old header, it now appears in `lh-meta`; remove any now-duplicate OSCA ID line that was part of the old header block only (do NOT remove the OSCA ID inside `.senior-name-block` if present).

- [ ] **Step 5: Add the signature + footer block before `</div>` of `.page`**

Just before the closing `</div>` that ends `<div class="page">` (end of document body), add:
```blade
    <div class="signatures">
        <div class="sig">
            <div class="sig-line">Prepared by (OSCA Encoder)</div>
        </div>
        <div class="sig">
            <div class="sig-line">Verified by (OSCA Head)</div>
        </div>
    </div>
    <div class="doc-footer">
        Generated on {{ now()->format('F j, Y g:i A') }} · {{ auth()->user()->name ?? 'OSCA System' }} · AgeSense OSCA Decision-Support System
    </div>
```

- [ ] **Step 6: Apply the WHO label fixes in the PDF body**

In the risk-score / domain section of the PDF body, replace any domain labels
`Physical`/`Physical Capacity` → `Intrinsic Capacity`, and `Functioning`/`Daily Functioning`
→ `Functional Ability` (Grep within `pdf.blade.php` for these strings; `Environment`
stays). If the score table renders domains from an array, update the labels there.

- [ ] **Step 7: Run test, verify it PASSES**

Run: `php artisan test --filter exported_pdf_template_is_formal_and_uses_corrected_labels`
Expected: PASS

- [ ] **Step 8: Smoke-render the real PDF**

Run: `php artisan test --filter Batch2RecordsRecommendations` (no regressions). Then manually
hit the export route in the browser for a seeded senior and confirm DomPDF renders the
letterhead, forest palette, and signature footer without layout breakage.

- [ ] **Step 9: Commit**

```bash
git add resources/views/seniors/pdf.blade.php tests/Feature/Batch2RecordsRecommendationsTest.php
git commit -m "feat(seniors): redesign export PDF as formal OSCA document"
```

---

## Task 6: Full-suite verification

- [ ] **Step 1: Run the batch test file**

Run: `php artisan test --filter Batch2RecordsRecommendations`
Expected: PASS (all Task 1-5 assertions).

- [ ] **Step 2: Run the full suite**

Run: `php artisan test`
Expected: PASS, no regressions.

- [ ] **Step 3: Manual smoke checklist**

- Senior record ML strip: "Intrinsic Capacity", "Environment", "Functional Ability", "Overall risk" caption, full cluster label.
- A senior with 2+ ML results: Recommendations page + counts show only the latest set.
- Recommendations index search by name and OSCA ID; composes with filters; pagination keeps the query.
- Export PDF renders as a formal document (letterhead, forest palette, signature footer, corrected labels).

---

## Self-Review Notes

- **Spec coverage:** Req 1 (ML labels) → Task 1; Req 2 (PDF) → Task 5; Req 3 (dup recs: relationship, controller, stats) → Tasks 2+3; Req 4 (search) → Task 4. All covered.
- **Type/name consistency:** `scopeCurrent` (Recommendation) → used as `->current()` in `currentRecommendations()`, controller stats, and tests — consistent. `currentRecommendations` relationship name used identically in model, controller `whereHas`/`withCount`, and tests. `makeSenior`/`makeMlResult`/`makeRec` helpers defined in Task 1/Task 2 and reused later.
- **No placeholders:** every code step shows concrete content; PDF redesign provides full style/markup blocks plus explicit Grep-and-replace instructions.
- **DomPDF safety:** redesign uses `display:table` (not flex/grid) for letterhead and signatures, matching the template's existing grid approach.
