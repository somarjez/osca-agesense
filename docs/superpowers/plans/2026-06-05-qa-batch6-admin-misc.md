# QA Batch 6 — Admin & Misc Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add search to Batch Analysis, fix the Activity Log "Delete Selected" button, give Export Registry a landing page with previews + download, and add a password show/hide toggle to User Management — the final QA batch.

**Architecture:** Laravel 11 + Livewire 3 + Blade + Alpine + Tailwind. Three controller-rendered pages get small server-side additions (batch search, registry landing); two are pure Blade/Alpine fixes (activity-log delete, password eye). Tests are PHPUnit feature tests with `DatabaseTransactions`.

**Tech Stack:** PHP 8.2, Laravel, Blade, Alpine.js, Tailwind, Maatwebsite/Excel, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-06-05-qa-batch6-admin-misc-design.md`

---

## File Structure

- `app/Http/Controllers/MlController.php` — `batchIndex` search (Task 1).
- `resources/views/ml/batch.blade.php` — search input (Task 1).
- `resources/views/activity_log/index.blade.php` — Delete-Selected bar fix (Task 2).
- `routes/reports.php` — `reports.registry` landing route (Task 3).
- `app/Http/Controllers/ReportController.php` — `registryIndex()` (Task 3).
- `resources/views/reports/registry.blade.php` — new landing view (Task 3).
- `resources/views/layouts/app.blade.php` — sidebar link → landing (Task 3).
- `resources/views/users/create.blade.php` — password eye toggles (Task 4).
- `tests/Feature/Batch6AdminMiscTest.php` — feature tests (Tasks 1, 3).

---

## Task 1: Batch Analysis — search

**Files:**
- Modify: `app/Http/Controllers/MlController.php` (`batchIndex`)
- Modify: `resources/views/ml/batch.blade.php`
- Test: `tests/Feature/Batch6AdminMiscTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Batch6AdminMiscTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\QolSurvey;
use App\Models\SeniorCitizen;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Batch6AdminMiscTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $viewer;

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

        $this->viewer = User::firstOrCreate(
            ['email' => 'viewer@osca.local'],
            ['name' => 'OSCA Viewer', 'password' => Hash::make('password')]
        );
        $this->viewer->syncRoles(['viewer']);
    }

    /** A senior with a QoL survey (so it appears in the batch "eligible" list). */
    private function makeEligibleSenior(string $first, string $last): SeniorCitizen
    {
        $senior = SeniorCitizen::create([
            'osca_id' => SeniorCitizen::generateOscaId('Anibong'),
            'first_name' => $first,
            'last_name' => $last,
            'barangay' => 'Anibong',
            'date_of_birth' => '1950-01-01',
            'household_size' => 1,
            'num_children' => 0,
            'num_working_children' => 0,
        ]);

        QolSurvey::create([
            'senior_citizen_id' => $senior->id,
            'survey_date' => now()->format('Y-m-d'),
            'status' => 'processed',
        ]);

        return $senior;
    }

    #[Test]
    public function batch_analysis_search_filters_by_name(): void
    {
        $this->makeEligibleSenior('Aurelia', 'Batchmatch');
        $this->makeEligibleSenior('Benigno', 'Batchother');

        $this->actingAs($this->admin)
            ->get(route('ml.batch', ['search' => 'Aurelia']))
            ->assertOk()
            ->assertSee('Aurelia Batchmatch')
            ->assertDontSee('Benigno Batchother');
    }
}
```

- [ ] **Step 2: Run test, verify it FAILS**

Run: `php artisan test --filter batch_analysis_search_filters_by_name`
Expected: FAIL — search is ignored; both names appear.

- [ ] **Step 3: Add search to `batchIndex`**

In `app/Http/Controllers/MlController.php`, change the signature and the `$pending` query:
```php
    public function batchIndex()
```
to:
```php
    public function batchIndex(Request $request)
```
and update the `$pending` query (`:74-78`) to:
```php
        $pending = SeniorCitizen::active()
            ->whereHas('latestQolSurvey')
            ->with(['latestQolSurvey', 'latestMlResult'])
            ->when($request->search, fn ($q, $term) => $q->where(function ($w) use ($term) {
                $w->where('osca_id', 'like', "%{$term}%")
                    ->orWhereRaw("LOWER(CONCAT(first_name, ' ', last_name)) LIKE ?", ['%'.strtolower($term).'%']);
            }))
            ->paginate(25)
            ->withQueryString();
```
Confirm `Illuminate\Http\Request` is imported in `MlController` (it is — `batchRun(Request $request)` exists).

- [ ] **Step 4: Add the search input to the table card head**

In `resources/views/ml/batch.blade.php`, the "Eligible Seniors" card head (`:197-202`) is:
```blade
        <div class="card-head">
            <div>
                <div class="card-title">Eligible Seniors</div>
                <div class="card-sub">Seniors with at least one QoL survey · {{ $totalEligible }} total</div>
            </div>
        </div>
```
Replace it with (adds a GET search form on the right of the head):
```blade
        <div class="card-head">
            <div>
                <div class="card-title">Eligible Seniors</div>
                <div class="card-sub">Seniors with at least one QoL survey · {{ $totalEligible }} total</div>
            </div>
            <form method="GET" action="{{ route('ml.batch') }}" class="flex items-center gap-2">
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Search name or OSCA ID…" class="form-input text-[12px] max-w-[200px]">
                @if (request('search'))
                <a href="{{ route('ml.batch') }}" class="btn btn-ghost text-[12px]">Clear</a>
                @endif
            </form>
        </div>
```

- [ ] **Step 5: Run test, verify it PASSES**

Run: `php artisan test --filter batch_analysis_search_filters_by_name`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/MlController.php resources/views/ml/batch.blade.php tests/Feature/Batch6AdminMiscTest.php
git commit -m "feat(ml): search the Batch Analysis eligible-seniors list"
```

---

## Task 2: Activity Log — fix "Delete Selected"

The backend (`bulkDestroy`) is correct and covered by `ActivityLogDeleteTest`. This is a
frontend fix: the floating bar's Delete button uses a fragile native `confirm()` inside
`@click.prevent`. Replace it with the standard `<x-confirm-modal>` flow and ensure the bar
is clearly visible in light mode.

**Files:**
- Modify: `resources/views/activity_log/index.blade.php` (`:137-164`)

- [ ] **Step 1: Reproduce (confirm root cause)**

Run the app (`php artisan serve` + `npm run dev`), open Activity Log as admin, tick some
rows. Observe whether the floating bar appears and whether "Delete Selected" actually
deletes. Note the failure (e.g. native confirm not firing / submit not happening / bar
hard to see). This confirms the fix below addresses the real cause; record findings in the
commit message.

- [ ] **Step 2: Replace the floating-bar block**

In `resources/views/activity_log/index.blade.php`, replace the floating-bar `<div>`
(`:137-164`, the `<div x-show="selected.length > 0" ...>` through its closing `</div>`)
with:
```blade
    <div x-show="selected.length > 0" x-cloak x-transition
         x-data="{ confirmOpen: false }"
         class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50
                bg-ink-900 dark:bg-[#1a221e] text-white rounded-xl shadow-2xl ring-1 ring-white/15
                flex items-center gap-4 px-5 py-3 text-sm">

        <span x-text="`${selected.length} selected`" class="font-medium tabular-nums"></span>

        <form x-ref="bulkDeleteForm" method="POST" action="{{ route('activity-log.bulk-destroy') }}">
            @csrf @method('DELETE')
            <template x-for="id in selected" :key="id">
                <input type="hidden" name="ids[]" :value="id">
            </template>
            <button type="button" @click="confirmOpen = true"
                    class="btn btn-danger text-xs py-1.5">
                Delete Selected
            </button>
        </form>

        <button @click="selected = []"
                class="text-white/70 hover:text-white text-xs underline underline-offset-2">
            Deselect all
        </button>

        <x-confirm-modal show="confirmOpen"
                         title="Delete selected log entries?"
                         confirm="$refs.bulkDeleteForm.submit()"
                         confirm-label="Delete permanently">
            <p>The selected activity log entries will be <strong class="text-ink-900 dark:text-[#e4e1d8]">permanently</strong> deleted. This cannot be undone.</p>
        </x-confirm-modal>
    </div>
```
This keeps the selection mechanism and the hidden-`ids[]` form, but: (a) the Delete button
now opens the confirm modal (`confirmOpen`) instead of a native `confirm()`; (b) the modal
submits `$refs.bulkDeleteForm` — the proven pattern used by the QoL list delete; (c) the
bar gets `shadow-2xl ring-1 ring-white/15` and an explicit dark-mode background so it reads
clearly in both themes. `confirmOpen` is local to this bar's nested `x-data`; `selected`
is inherited from the page-level `x-data`. Confirm `btn-danger` exists (it does — used by
`<x-confirm-modal>`'s danger tone).

- [ ] **Step 3: Verify backend test still green + page renders**

Run: `php artisan test --filter ActivityLogDelete`
Expected: PASS (7 tests — backend unaffected). Then manually: select rows → bar visible in
light mode → Delete Selected opens the modal → confirming deletes the rows.

- [ ] **Step 4: Commit**

```bash
git add resources/views/activity_log/index.blade.php
git commit -m "fix(activity-log): Delete Selected uses confirm-modal + visible in light mode"
```

---

## Task 3: Export Registry — landing page

**Files:**
- Modify: `routes/reports.php`
- Modify: `app/Http/Controllers/ReportController.php` (new `registryIndex`)
- Create: `resources/views/reports/registry.blade.php`
- Modify: `resources/views/layouts/app.blade.php` (sidebar link)
- Test: `tests/Feature/Batch6AdminMiscTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `Batch6AdminMiscTest`:

```php
    #[Test]
    public function registry_landing_page_renders_for_admin_with_download_link(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.registry'))
            ->assertOk()
            ->assertSee('Senior Registry')
            ->assertSee(route('reports.registry.export'), false);
    }

    #[Test]
    public function registry_landing_page_is_forbidden_for_viewer(): void
    {
        $this->actingAs($this->viewer)
            ->get(route('reports.registry'))
            ->assertForbidden();
    }
```

- [ ] **Step 2: Run tests, verify they FAIL**

Run: `php artisan test --filter registry_landing_page`
Expected: FAIL — route `reports.registry` does not exist.

- [ ] **Step 3: Add the admin-only landing route**

In `routes/reports.php`, inside the `Route::middleware('role:admin')->group(...)` block
(which already has `registry.export`), add — directly ABOVE the `registry.export` line:
```php
        Route::get('/registry', [ReportController::class, 'registryIndex'])->name('registry');
```

- [ ] **Step 4: Add the `registryIndex` controller method**

In `app/Http/Controllers/ReportController.php`, add this method immediately BEFORE the
existing `exportRegistry()` method:
```php
    /**
     * Export Registry landing page — summary previews + a sample of the registry,
     * with a button to download the full XLSX. Admin only.
     */
    public function registryIndex()
    {
        $latestIds = MlResult::select(DB::raw('MAX(id) as id'))
            ->groupBy('senior_citizen_id')
            ->pluck('id');

        $total = SeniorCitizen::active()->count();
        $assessed = SeniorCitizen::active()->whereHas('latestMlResult')->count();

        $riskBreakdown = MlResult::whereIn('id', $latestIds)
            ->whereHas('seniorCitizen', fn ($q) => $q->active())
            ->select('overall_risk_level', DB::raw('COUNT(*) as count'))
            ->groupBy('overall_risk_level')
            ->pluck('count', 'overall_risk_level');

        $barangaysCovered = SeniorCitizen::active()->distinct('barangay')->count('barangay');

        $preview = SeniorCitizen::active()
            ->with('latestMlResult')
            ->orderBy('barangay')
            ->orderBy('last_name')
            ->limit(12)
            ->get();

        $stats = [
            'total' => $total,
            'assessed' => $assessed,
            'not_assessed' => $total - $assessed,
            'high' => (int) ($riskBreakdown['HIGH'] ?? 0),
            'moderate' => (int) ($riskBreakdown['MODERATE'] ?? 0),
            'low' => (int) ($riskBreakdown['LOW'] ?? 0),
            'barangays' => $barangaysCovered,
        ];

        return view('reports.registry', compact('stats', 'preview'));
    }
```

- [ ] **Step 5: Create the landing view**

Create `resources/views/reports/registry.blade.php`:
```blade
@extends('layouts.app')
@section('page-title', 'Export Registry')
@section('page-subtitle', 'Preview and download the full senior citizen registry')

@section('content')
<div class="space-y-5">

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <div class="eyebrow text-forest-600 dark:text-forest-400 mb-1">Records</div>
            <h2 class="font-display text-2xl leading-tight text-ink-900 dark:text-[#e4e1d8]">Senior Registry</h2>
            <p class="text-sm text-ink-500 dark:text-[#8a9087] mt-0.5">A complete export of all active senior citizen records with their latest assessment.</p>
        </div>
        <a href="{{ route('reports.registry.export') }}" class="btn btn-primary">
            <x-heroicon-o-arrow-down-tray class="w-4 h-4" /> Download Full Registry (XLSX)
        </a>
    </div>

    {{-- Summary previews --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
        <div class="kpi"><div class="kpi-rule bg-forest-500"></div><div class="kpi-label">Active Seniors</div><div class="kpi-value">{{ number_format($stats['total']) }}</div></div>
        <div class="kpi"><div class="kpi-rule bg-forest-400"></div><div class="kpi-label">Assessed</div><div class="kpi-value">{{ number_format($stats['assessed']) }}</div></div>
        <div class="kpi"><div class="kpi-rule bg-ink-300"></div><div class="kpi-label">Not Assessed</div><div class="kpi-value">{{ number_format($stats['not_assessed']) }}</div></div>
        <div class="kpi"><div class="kpi-rule bg-high-500"></div><div class="kpi-label">High Risk</div><div class="kpi-value text-high-700">{{ number_format($stats['high']) }}</div></div>
        <div class="kpi"><div class="kpi-rule bg-moderate-500"></div><div class="kpi-label">Moderate</div><div class="kpi-value text-moderate-700">{{ number_format($stats['moderate']) }}</div></div>
        <div class="kpi"><div class="kpi-rule bg-low-500"></div><div class="kpi-label">Barangays</div><div class="kpi-value">{{ number_format($stats['barangays']) }}</div></div>
    </div>

    {{-- Preview table --}}
    <div class="card overflow-hidden">
        <div class="card-head">
            <div class="card-title">Preview</div>
            <span class="text-[11.5px] text-ink-400 dark:text-[#6b7570]">First {{ $preview->count() }} of {{ number_format($stats['total']) }} records · download for the full set</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="th">OSCA ID</th>
                        <th class="th">Name</th>
                        <th class="th">Barangay</th>
                        <th class="th text-center">Risk Level</th>
                        <th class="th">Health Group</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($preview as $senior)
                    @php $ml = $senior->latestMlResult; @endphp
                    <tr class="hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors">
                        <td class="td font-mono text-[12px] text-ink-600 dark:text-[#b0b5b2]">{{ $senior->osca_id }}</td>
                        <td class="td font-medium text-ink-900 dark:text-[#e4e1d8]">{{ $senior->full_name }}</td>
                        <td class="td text-ink-500 dark:text-[#8a9087]">{{ $senior->barangay }}</td>
                        <td class="td text-center">
                            @if ($ml)
                            <span class="badge {{ match($ml->overall_risk_level) {
                                'HIGH' => 'badge-high', 'MODERATE' => 'badge-moderate', 'LOW' => 'badge-low', default => 'badge-neutral',
                            } }}">{{ $ml->overall_risk_level }}</span>
                            @else
                            <span class="text-ink-300 dark:text-[#4a5550] text-xs">—</span>
                            @endif
                        </td>
                        <td class="td text-[12px] text-ink-500 dark:text-[#8a9087]">{{ $ml?->cluster_name ?? '—' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="td text-center py-12 text-ink-400">No records yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-doc-footer signatory="OSCA Officer / Reviewer" />
</div>
@endsection
```

- [ ] **Step 6: Point the sidebar link at the landing page**

In `resources/views/layouts/app.blade.php` (`:185`), change:
```blade
            <a href="{{ route('reports.registry.export') }}"
```
to:
```blade
            <a href="{{ route('reports.registry') }}"
```
(Leave the rest of the link — icon, label "Export Registry" — unchanged.)

- [ ] **Step 7: Run tests, verify they PASS**

Run: `php artisan test --filter registry_landing_page`
Expected: PASS (admin sees the page + download link; viewer forbidden).

- [ ] **Step 8: Commit**

```bash
git add routes/reports.php app/Http/Controllers/ReportController.php resources/views/reports/registry.blade.php resources/views/layouts/app.blade.php tests/Feature/Batch6AdminMiscTest.php
git commit -m "feat(reports): Export Registry landing page with previews + download"
```

---

## Task 4: User Management — password eye toggle

**Files:**
- Modify: `resources/views/users/create.blade.php` (`:60-78`)

- [ ] **Step 1: Add show/hide toggles to both password fields**

In `resources/views/users/create.blade.php`, replace the Password block (`:61-70`):
```blade
                <div class="border-t border-paper-rule dark:border-[#2b3530] pt-5">
                    <label class="eyebrow block mb-1.5" for="password">Password</label>
                    <input id="password" type="password" name="password"
                           class="form-input w-full @error('password') border-critical-400 @enderror"
                           placeholder="Minimum 8 characters"
                           required>
                    @error('password')
                    <p class="mt-1 text-xs text-critical-700 dark:text-[#e08070]">{{ $message }}</p>
                    @enderror
                </div>
```
with:
```blade
                <div class="border-t border-paper-rule dark:border-[#2b3530] pt-5">
                    <label class="eyebrow block mb-1.5" for="password">Password</label>
                    <div class="relative" x-data="{ show: false }">
                        <input id="password" name="password" :type="show ? 'text' : 'password'"
                               class="form-input w-full pr-10 @error('password') border-critical-400 @enderror"
                               placeholder="Minimum 8 characters"
                               required>
                        <button type="button" @click="show = !show" tabindex="-1"
                                class="absolute inset-y-0 right-0 px-3 flex items-center text-ink-400 hover:text-ink-700 dark:hover:text-[#c8c4bc]"
                                :aria-label="show ? 'Hide password' : 'Show password'">
                            <x-heroicon-o-eye x-show="!show" class="w-4 h-4" />
                            <x-heroicon-o-eye-slash x-show="show" x-cloak class="w-4 h-4" />
                        </button>
                    </div>
                    @error('password')
                    <p class="mt-1 text-xs text-critical-700 dark:text-[#e08070]">{{ $message }}</p>
                    @enderror
                </div>
```
Then replace the Confirm Password block (`:72-78`):
```blade
                <div>
                    <label class="eyebrow block mb-1.5" for="password_confirmation">Confirm Password</label>
                    <input id="password_confirmation" type="password" name="password_confirmation"
                           class="form-input w-full"
                           placeholder="Re-enter password"
                           required>
                </div>
```
with:
```blade
                <div>
                    <label class="eyebrow block mb-1.5" for="password_confirmation">Confirm Password</label>
                    <div class="relative" x-data="{ show: false }">
                        <input id="password_confirmation" name="password_confirmation" :type="show ? 'text' : 'password'"
                               class="form-input w-full pr-10"
                               placeholder="Re-enter password"
                               required>
                        <button type="button" @click="show = !show" tabindex="-1"
                                class="absolute inset-y-0 right-0 px-3 flex items-center text-ink-400 hover:text-ink-700 dark:hover:text-[#c8c4bc]"
                                :aria-label="show ? 'Hide password' : 'Show password'">
                            <x-heroicon-o-eye x-show="!show" class="w-4 h-4" />
                            <x-heroicon-o-eye-slash x-show="show" x-cloak class="w-4 h-4" />
                        </button>
                    </div>
                </div>
```

- [ ] **Step 2: Verify it renders**

Run: `php artisan view:clear` then load `/users/create` as admin; toggle each eye and
confirm the field reveals/hides its value. (No automated test — Alpine UI behavior.)

- [ ] **Step 3: Commit**

```bash
git add resources/views/users/create.blade.php
git commit -m "feat(users): password show/hide eye toggle on create form"
```

---

## Task 5: Verification + Pint

- [ ] **Step 1: Batch test file**

Run: `php artisan test --filter Batch6AdminMisc`
Expected: PASS (batch search + registry landing/gating).

- [ ] **Step 2: Full suite**

Run: `php artisan test`
Expected: PASS, no regressions (ActivityLogDelete still green).

- [ ] **Step 3: Pint the changed PHP (CI gate)**

Run: `./vendor/bin/pint app/Http/Controllers/MlController.php app/Http/Controllers/ReportController.php tests/Feature/Batch6AdminMiscTest.php`
Then verify the only diffs are line-endings (compare with `git diff --ignore-cr-at-eol`,
which should show nothing logical). Do NOT run bare `./vendor/bin/pint` (it rewrites CRLF
on ~120 unrelated files locally; those are not CI violations). Commit any real fix:
```bash
git add -A && git commit -m "style: pint" || echo "nothing to fix"
```

- [ ] **Step 4: Manual smoke checklist**

- Batch Analysis: search narrows the eligible list; pagination preserves the query.
- Activity Log: select rows → visible bar (light mode) → Delete Selected → confirm modal →
  rows deleted.
- Export Registry: sidebar opens the landing page (summary cards + preview table); Download
  produces the XLSX; viewer is forbidden.
- User Management: each eye toggles its password field's visibility.

---

## Self-Review Notes

- **Spec coverage:** Req 1 (batch search) → Task 1; Req 2 (activity-log delete) → Task 2;
  Req 3 (registry landing) → Task 3; Req 4 (password eye) → Task 4. All covered.
- **Name/route consistency:** `reports.registry` defined (Task 3 Step 3), used in the view
  link, the sidebar (Step 6), and both tests; `registryIndex` returns `compact('stats',
  'preview')` consumed by the view; `ml.batch` route used in controller search + view +
  test; `activity-log.bulk-destroy` form action unchanged; `$refs.bulkDeleteForm` defined
  and referenced within the same nested `x-data`.
- **No placeholders:** every code step shows concrete content.
- **Pint:** Task 5 scopes Pint to the three changed PHP files.
