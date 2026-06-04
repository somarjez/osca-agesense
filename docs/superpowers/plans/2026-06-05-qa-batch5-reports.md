# QA Batch 5 — Reports (Risk & Barangay) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add search + domain-score sorting + a correct 4-cluster filter to the Risk Report, make its CSV export reflect the on-screen filters (not HIGH-only), and fix the Barangay Report's card layout while paginating/searching its roster.

**Architecture:** Laravel 11 + Livewire 3 + Blade + Tailwind. The Risk Report is a Livewire component (`RiskReport`); its CSV export is a controller action that re-applies the same filters from query params. The Barangay Report is a controller-rendered Blade page; the roster becomes a paginated+searchable query while the aggregate cards stay barangay-wide. Tests are PHPUnit feature tests (`Livewire::test` + HTTP) with `DatabaseTransactions`.

**Tech Stack:** PHP 8.2, Laravel, Livewire 3, Blade, Tailwind, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-06-05-qa-batch5-reports-design.md`

---

## File Structure

- `app/Livewire/Reports/RiskReport.php` — search field, sort whitelist, search clause (Task 1).
- `resources/views/livewire/reports/risk-report.blade.php` — search input, 4-cluster select, sortable IC/Env/Func headers, filter-aware export link (Tasks 1, 2).
- `app/Http/Controllers/ReportController.php` — `exportRisk(Request)` filter-aware (Task 2); `barangay(Request, $brgy)` paginated roster (Task 3).
- `resources/views/reports/barangay.blade.php` — `items-start` grid + roster pagination/search (Task 3).
- `tests/Feature/Batch5ReportsTest.php` — feature tests (Tasks 1-3).

---

## Task 1: Risk Report — search, domain-score sorting, 4-cluster filter

**Files:**
- Modify: `app/Livewire/Reports/RiskReport.php`
- Modify: `resources/views/livewire/reports/risk-report.blade.php`
- Test: `tests/Feature/Batch5ReportsTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Batch5ReportsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\Reports\RiskReport;
use App\Models\MlResult;
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

class Batch5ReportsTest extends TestCase
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

    private function makeSeniorWithRisk(string $level, string $first, string $last, string $barangay = 'Anibong'): SeniorCitizen
    {
        $senior = SeniorCitizen::create([
            'osca_id' => SeniorCitizen::generateOscaId($barangay),
            'first_name' => $first,
            'last_name' => $last,
            'barangay' => $barangay,
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
        ]);

        $survey = QolSurvey::create([
            'senior_citizen_id' => $senior->id,
            'survey_date' => now()->format('Y-m-d'),
            'status' => 'processed',
        ]);

        MlResult::create([
            'senior_citizen_id' => $senior->id,
            'qol_survey_id' => $survey->id,
            'model_version' => '2.0.0',
            'prediction_source' => 'live_model',
            'overall_risk_level' => $level,
            'ic_risk' => 0.5, 'env_risk' => 0.5, 'func_risk' => 0.5,
            'composite_risk' => 0.5, 'wellbeing_score' => 0.5,
            'cluster_named_id' => 2,
            'scored_at' => now(), 'processed_at' => now(),
        ]);

        return $senior;
    }

    #[Test]
    public function risk_report_search_matches_name(): void
    {
        $this->makeSeniorWithRisk('HIGH', 'Aurelio', 'Searchtarget');
        $this->makeSeniorWithRisk('HIGH', 'Benigno', 'Otherperson');

        $this->actingAs($this->admin);

        Livewire::test(RiskReport::class)
            ->set('filterSearch', 'Aurelio')
            ->assertSee('Aurelio Searchtarget')
            ->assertDontSee('Benigno Otherperson');
    }

    #[Test]
    public function risk_report_sort_column_rejects_unlisted_columns(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(RiskReport::class)
            ->call('sortColumn', 'ic_risk')
            ->assertSet('sortBy', 'ic_risk')
            ->call('sortColumn', 'first_name; DROP TABLE')
            ->assertSet('sortBy', 'ic_risk'); // unchanged — not in whitelist
    }
}
```

- [ ] **Step 2: Run tests, verify they FAIL**

Run: `php artisan test --filter "risk_report_search_matches_name|risk_report_sort_column_rejects"`
Expected: FAIL — `filterSearch` property does not exist; `sortColumn` accepts any column.

- [ ] **Step 3: Update the RiskReport component**

In `app/Livewire/Reports/RiskReport.php`:

Add the search property after `$filterCluster` (`:19`):
```php
    public string $filterSearch = '';
```

In `render()`, add a search clause to the `$query` (after the `filterBarangay` `when`, before `->orderBy`):
```php
            ->when($this->filterSearch, fn ($q) => $q->whereHas('seniorCitizen', fn ($sq) => $sq
                ->where('osca_id', 'like', "%{$this->filterSearch}%")
                ->orWhereRaw("LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?", ['%'.strtolower($this->filterSearch).'%'])
            ))
```

Replace the entire `sortColumn()` method with a whitelisted version:
```php
    public function sortColumn(string $col): void
    {
        $allowed = ['composite_risk', 'overall_risk_level', 'ic_risk', 'env_risk', 'func_risk', 'wellbeing_score'];
        if (! in_array($col, $allowed, true)) {
            return;
        }

        if ($this->sortBy === $col) {
            $this->sortDir = $this->sortDir === 'desc' ? 'asc' : 'desc';
        } else {
            $this->sortBy = $col;
            $this->sortDir = 'desc';
        }

        $this->resetPage();
    }
```

Add a reset hook after `updatedFilterCluster()`:
```php
    public function updatedFilterSearch(): void
    {
        $this->resetPage();
    }
```

- [ ] **Step 4: Update the filter bar + cluster select + sortable headers in the view**

In `resources/views/livewire/reports/risk-report.blade.php`:

(a) Add a search input right after the funnel icon (`:28`), before the risk select:
```blade
            <input type="text" wire:model.live.debounce.300ms="filterSearch"
                   placeholder="Search name or OSCA ID…" class="form-input max-w-[220px]">
```

(b) Replace the stale cluster select (`:44-49`) with the four real health groups:
```blade
            <select wire:model.live="filterCluster" class="form-select max-w-[280px]">
                <option value="">All Health Groups</option>
                <option value="1">C1 · High Functioning / Well-Supported Seniors</option>
                <option value="2">C2 · Stable Ageing / Moderate Support Needs</option>
                <option value="3">C3 · Environmentally and Financially Vulnerable Seniors</option>
                <option value="4">C4 · Low Functioning / Multi-Domain Priority Seniors</option>
            </select>
```

(c) Update the Clear control (`:51-56`) to include `filterSearch`:
```blade
            @if ($filterRisk || $filterBarangay || $filterCluster || $filterSearch)
            <button wire:click="$set('filterRisk',''); $set('filterBarangay',''); $set('filterCluster',''); $set('filterSearch','')"
                    class="btn btn-ghost text-[12.5px] gap-1.5">
                <x-heroicon-o-x-mark class="w-3.5 h-3.5" /> Clear
            </button>
            @endif
```

(d) Make the IC / Env / Func headers (`:80-82`) sortable. Replace those three `<th>`s:
```blade
                        <th class="th text-center cursor-pointer select-none" wire:click="sortColumn('ic_risk')">
                            IC {{ $sortBy === 'ic_risk' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' }}
                        </th>
                        <th class="th text-center cursor-pointer select-none" wire:click="sortColumn('env_risk')">
                            Env {{ $sortBy === 'env_risk' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' }}
                        </th>
                        <th class="th text-center cursor-pointer select-none" wire:click="sortColumn('func_risk')">
                            Func {{ $sortBy === 'func_risk' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' }}
                        </th>
```

- [ ] **Step 5: Run tests, verify they PASS**

Run: `php artisan test --filter "risk_report_search_matches_name|risk_report_sort_column_rejects"`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Reports/RiskReport.php resources/views/livewire/reports/risk-report.blade.php tests/Feature/Batch5ReportsTest.php
git commit -m "feat(reports): risk report search, domain-score sorting, 4-cluster filter"
```

---

## Task 2: Risk CSV export reflects the active filters

**Files:**
- Modify: `app/Http/Controllers/ReportController.php` (`exportRisk`)
- Modify: `resources/views/livewire/reports/risk-report.blade.php` (export link)
- Test: `tests/Feature/Batch5ReportsTest.php`

- [ ] **Step 1: Write the failing test**

Append to `Batch5ReportsTest`:

```php
    #[Test]
    public function risk_export_includes_all_levels_by_default_and_respects_risk_filter(): void
    {
        $this->makeSeniorWithRisk('HIGH', 'Zelda', 'Highexport');
        $this->makeSeniorWithRisk('LOW', 'Yanni', 'Lowexport');

        // Default export — both levels present.
        $all = $this->actingAs($this->admin)
            ->get(route('reports.risk.export'))
            ->streamedContent();
        $this->assertStringContainsString('Zelda Highexport', $all);
        $this->assertStringContainsString('Yanni Lowexport', $all);

        // Filtered to LOW — only the low-risk senior.
        $lowOnly = $this->actingAs($this->admin)
            ->get(route('reports.risk.export', ['risk' => 'low']))
            ->streamedContent();
        $this->assertStringContainsString('Yanni Lowexport', $lowOnly);
        $this->assertStringNotContainsString('Zelda Highexport', $lowOnly);
    }
```

- [ ] **Step 2: Run test, verify it FAILS**

Run: `php artisan test --filter risk_export_includes_all_levels_by_default`
Expected: FAIL — default export omits the LOW senior (hardcoded HIGH-only).

- [ ] **Step 3: Make `exportRisk` filter-aware**

In `app/Http/Controllers/ReportController.php`, replace the `exportRisk()` method. The new version takes the request, drops the hardcoded HIGH filter, and applies the same filters/sort:
```php
    public function exportRisk(Request $request)
    {
        $activeSeniorIds = SeniorCitizen::active()->pluck('id');
        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->whereIn('senior_citizen_id', $activeSeniorIds)
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        $allowedSorts = ['composite_risk', 'overall_risk_level', 'ic_risk', 'env_risk', 'func_risk', 'wellbeing_score'];
        $sortBy = in_array($request->sort, $allowedSorts, true) ? $request->sort : 'composite_risk';
        $sortDir = $request->dir === 'asc' ? 'asc' : 'desc';

        $data = SeniorCitizen::active()
            ->join('ml_results', function ($join) use ($latestIds) {
                $join->on('senior_citizens.id', '=', 'ml_results.senior_citizen_id')
                    ->whereIn('ml_results.id', $latestIds);
            })
            ->when($request->risk, fn ($q, $risk) => $q->where('ml_results.overall_risk_level', strtoupper($risk)))
            ->when($request->barangay, fn ($q, $b) => $q->where('senior_citizens.barangay', $b))
            ->when($request->cluster, fn ($q, $c) => $q->where('ml_results.cluster_named_id', $c))
            ->when($request->search, fn ($q, $term) => $q->where(function ($w) use ($term) {
                $w->where('senior_citizens.osca_id', 'like', "%{$term}%")
                    ->orWhereRaw("LOWER(CONCAT(senior_citizens.first_name,' ',senior_citizens.last_name)) LIKE ?", ['%'.strtolower($term).'%']);
            }))
            ->select(
                'senior_citizens.osca_id',
                DB::raw("CONCAT(senior_citizens.first_name,' ',senior_citizens.last_name) as name"),
                'senior_citizens.barangay',
                DB::raw(DbHelper::ageExpr('senior_citizens.date_of_birth')),
                'ml_results.overall_risk_level',
                'ml_results.composite_risk',
                'ml_results.ic_risk_level',
                'ml_results.env_risk_level',
                'ml_results.func_risk_level',
                'ml_results.processed_at'
            )
            ->orderBy("ml_results.{$sortBy}", $sortDir)
            ->get();

        $filename = 'osca_risk_report_'.now()->format('Ymd_His').'.csv';

        $callback = function () use ($data) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['OSCA ID', 'Name', 'Barangay', 'Age', 'Risk Level',
                'Composite Risk', 'IC Risk Level', 'Env Risk Level', 'Func Risk Level', 'Processed At']);
            foreach ($data as $row) {
                fputcsv($file, array_values($row->toArray()));
            }
            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
```
Confirm `Illuminate\Http\Request` is imported in the controller (it is — other methods type-hint `Request`).

- [ ] **Step 4: Make the export link carry the current filter state**

In `resources/views/livewire/reports/risk-report.blade.php`, replace the export anchor (`:59`):
```blade
                <a href="{{ route('reports.risk.export') }}" class="btn">
```
with:
```blade
                <a href="{{ route('reports.risk.export', array_filter([
                        'risk' => $filterRisk,
                        'barangay' => $filterBarangay,
                        'cluster' => $filterCluster,
                        'search' => $filterSearch,
                        'sort' => $sortBy,
                        'dir' => $sortDir,
                    ])) }}" class="btn">
```
(`array_filter` drops empties so the URL stays clean.)

- [ ] **Step 5: Run tests, verify they PASS**

Run: `php artisan test --filter risk_export_includes_all_levels_by_default`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ReportController.php resources/views/livewire/reports/risk-report.blade.php tests/Feature/Batch5ReportsTest.php
git commit -m "feat(reports): risk CSV export reflects active filters (all levels by default)"
```

---

## Task 3: Barangay Report — card layout fix + roster pagination/search

**Files:**
- Modify: `app/Http/Controllers/ReportController.php` (`barangay`)
- Modify: `resources/views/reports/barangay.blade.php`
- Test: `tests/Feature/Batch5ReportsTest.php`

- [ ] **Step 1: Write the failing test**

Append to `Batch5ReportsTest`:

```php
    #[Test]
    public function barangay_roster_is_searchable(): void
    {
        $this->makeSeniorWithRisk('HIGH', 'Aniceta', 'Rosterhit', 'Anibong');
        $this->makeSeniorWithRisk('HIGH', 'Bartolome', 'Rostermiss', 'Anibong');

        $this->actingAs($this->admin)
            ->get(route('reports.barangay', ['brgy' => 'Anibong', 'roster_search' => 'Aniceta']))
            ->assertOk()
            ->assertSee('Aniceta Rosterhit')
            ->assertDontSee('Bartolome Rostermiss');
    }
```

- [ ] **Step 2: Run test, verify it FAILS**

Run: `php artisan test --filter barangay_roster_is_searchable`
Expected: FAIL — the roster ignores `roster_search`; both names appear.

- [ ] **Step 3: Add a paginated, searchable roster in the controller**

In `app/Http/Controllers/ReportController.php`, change the `barangay` signature and add the
roster query. Update:
```php
    public function barangay(string $brgy)
    {
```
to:
```php
    public function barangay(Request $request, string $brgy)
    {
```
Then, immediately after the existing `$seniors = SeniorCitizen::active()->where('barangay', $brgy)->with('latestMlResult')->orderBy('last_name')->get();` block, add a separate paginated roster:
```php
        // Roster table — paginated + searchable (independent of the barangay-wide aggregates).
        $roster = SeniorCitizen::active()
            ->where('barangay', $brgy)
            ->with('latestMlResult')
            ->when($request->roster_search, fn ($q, $term) => $q->where(function ($w) use ($term) {
                $w->where('osca_id', 'like', "%{$term}%")
                    ->orWhereRaw("LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?", ['%'.strtolower($term).'%']);
            }))
            ->orderBy('last_name')
            ->paginate(25)
            ->withQueryString();
```
Add `'roster'` to the `compact(...)` in the `return view('reports.barangay', compact(...))`:
```php
        return view('reports.barangay', compact(
            'brgy', 'barangays', 'seniors', 'roster',
            'riskDist', 'clusterDist', 'domainAvgs',
            'urgentCount', 'pendingRecs'
        ));
```
(`$seniors` stays for the `$total`/`$surveyed` KPIs — those remain barangay-wide.)

- [ ] **Step 4: Fix the card grid + use the paginated roster in the view**

In `resources/views/reports/barangay.blade.php`:

(a) Fix the stretch — change the domain/health-group grid (`:65`):
```blade
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
```
to:
```blade
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
```

(b) Add a roster search box in the roster card head (after the `{{ $total }} seniors` span, `:162`). Replace that span line:
```blade
            <span class="text-[11.5px] text-ink-400 dark:text-[#6b7570] tnum">{{ $total }} seniors</span>
```
with:
```blade
            <div class="flex items-center gap-2">
                <form method="GET" action="{{ route('reports.barangay', ['brgy' => $brgy]) }}">
                    <input type="text" name="roster_search" value="{{ request('roster_search') }}"
                           placeholder="Search roster…" class="form-input text-[12px] max-w-[180px]">
                </form>
                <span class="text-[11.5px] text-ink-400 dark:text-[#6b7570] tnum">{{ $total }} seniors</span>
            </div>
```

(c) Change the roster loop (`:178`) from `$seniors` to `$roster`:
```blade
                    @forelse ($roster as $senior)
```
Leave the loop body unchanged. (The matching `@empty`/`@endforelse` stays.)

(d) Add pagination links after the roster table closes. Find the `</table>` that ends the
roster and the `</div>` after it; immediately after that closing `</div>` (still inside the
roster card), add:
```blade
        @if ($roster->hasPages())
        <div class="border-t border-paper-rule dark:border-[#2b3530] px-5 py-3">
            {{ $roster->withQueryString()->links() }}
        </div>
        @endif
```

- [ ] **Step 5: Run test, verify it PASSES**

Run: `php artisan test --filter barangay_roster_is_searchable`
Expected: PASS. Then `php artisan test --filter Batch5Reports` — all pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ReportController.php resources/views/reports/barangay.blade.php tests/Feature/Batch5ReportsTest.php
git commit -m "feat(reports): barangay card layout fix + paginated searchable roster"
```

---

## Task 4: Verification + Pint

- [ ] **Step 1: Batch test file**

Run: `php artisan test --filter Batch5Reports`
Expected: PASS (4 tests: search, sort whitelist, export, roster search).

- [ ] **Step 2: Full suite**

Run: `php artisan test`
Expected: PASS, no regressions.

- [ ] **Step 3: Pint the changed PHP files (CI gate)**

Run: `./vendor/bin/pint app/Livewire/Reports/RiskReport.php app/Http/Controllers/ReportController.php tests/Feature/Batch5ReportsTest.php`
Then: `./vendor/bin/pint --test app/Livewire/Reports/RiskReport.php app/Http/Controllers/ReportController.php tests/Feature/Batch5ReportsTest.php`
Expected: "passed". (Scope Pint to changed files — do NOT run bare `./vendor/bin/pint`; on this Windows checkout it reports CRLF diffs on ~120 unrelated files that are not CI violations.) Commit any fix:
```bash
git add -A && git commit -m "style: pint" || echo "nothing to fix"
```

- [ ] **Step 4: Manual smoke checklist**

- Risk Report: search by name/OSCA narrows the table; IC/Env/Func headers sort; cluster
  dropdown shows the four full titles and filters; Export CSV downloads rows matching the
  current filters (and all levels when unfiltered).
- Barangay Report: the "Average Domain Risk Scores" card no longer stretches awkwardly;
  the roster paginates and searches while the aggregate cards/`$total` reflect the whole
  barangay.

---

## Self-Review Notes

- **Spec coverage:** Req 1 (search) → Task 1; Req 2 (domain sorting + whitelist) → Task 1;
  Req 3 (4-cluster filter) → Task 1; Req 4 (filter-aware export) → Task 2; Req 5 (card
  layout) → Task 3; Req 6 (roster pagination/search) → Task 3. All covered.
- **Name/param consistency:** export query params `risk/barangay/cluster/search/sort/dir`
  used identically in the view link (Task 2 Step 4) and `exportRisk` (Task 2 Step 3); the
  `$allowedSorts` whitelist matches between `RiskReport::sortColumn` and `exportRisk`;
  `roster_search` used in controller (Task 3 Step 3) and view (Task 3 Step 4); `$roster`
  passed via compact and consumed in the loop + links.
- **No placeholders:** every code step shows concrete content.
- **Pint:** Task 4 scopes Pint to the three changed PHP files.
