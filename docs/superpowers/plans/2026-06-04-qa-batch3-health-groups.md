# QA Batch 3 — Health Groups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Health Groups page's section switcher prominent, redesign the Model Insights feature-importance bars (with corrected WHO labels), and let admins permanently delete cluster snapshots.

**Architecture:** Laravel 11 + Blade + Alpine.js + Tailwind (forest/paper/ink tokens). All UI changes are on `resources/views/reports/cluster.blade.php`; snapshot deletion adds one admin-only route and one `ReportController` method against the `ClusterSnapshot` model (no soft-deletes — deletes are permanent). Tests are PHPUnit feature tests using `DatabaseTransactions` and the seeded test DB.

**Tech Stack:** PHP 8.2, Laravel, Blade, Alpine.js, Tailwind, PHPUnit, Spatie permissions.

**Spec:** `docs/superpowers/specs/2026-06-04-qa-batch3-health-groups-design.md`

---

## File Structure

- `resources/views/reports/cluster.blade.php` — section switcher (Task 1), Model Insights labels + bars (Task 2), snapshot Delete UI (Task 3).
- `routes/reports.php` — admin-only delete route (Task 3).
- `app/Http/Controllers/ReportController.php` — `destroySnapshot()` (Task 3).
- `tests/Feature/Batch3HealthGroupsTest.php` — new feature tests (Tasks 1-3).

---

## Task 1: Prominent segmented section switcher

Replace the subtle underline tab strip with a forest-filled segmented pill group.

**Files:**
- Modify: `resources/views/reports/cluster.blade.php` (`:197-213`)
- Test: `tests/Feature/Batch3HealthGroupsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Batch3HealthGroupsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ClusterSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Batch3HealthGroupsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $encoder;

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

        $this->encoder = User::firstOrCreate(
            ['email' => 'encoder@osca.local'],
            ['name' => 'OSCA Encoder', 'password' => Hash::make('password')]
        );
        $this->encoder->syncRoles(['encoder']);
    }

    private function makeSnapshotForDate(string $date): void
    {
        foreach ([1, 2, 3, 4] as $cid) {
            ClusterSnapshot::create([
                'snapshot_date' => $date,
                'cluster_id' => $cid,
                'cluster_name' => "Group {$cid}",
                'member_count' => 10,
                'avg_composite_risk' => 0.4,
            ]);
        }
    }

    #[Test]
    public function health_groups_page_renders_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.cluster'))
            ->assertOk()
            ->assertSee('Model Insights')
            ->assertSee('Cluster Explorer')
            ->assertSee('Snapshot History');
    }
}
```

- [ ] **Step 2: Run test, verify it PASSES (baseline guard)**

Run: `php artisan test --filter health_groups_page_renders_for_admin`
Expected: PASS (the labels already exist). This is a regression guard for the Blade edits in Tasks 1-2 — if a later edit breaks the Blade, this fails.

- [ ] **Step 3: Replace the section tab strip with segmented pills**

In `resources/views/reports/cluster.blade.php`, replace the section tab strip block
(`:197-213`, the `{{-- Section tab strip --}}` `<div>` containing the `@foreach` of
underline buttons) with:

```blade
        {{-- Section switcher — prominent segmented pills --}}
        <div class="px-5 pt-4 pb-3 border-b border-paper-rule dark:border-[#2b3530]">
            <div class="inline-flex flex-wrap gap-1 bg-paper-2 dark:bg-[#202a26] border border-paper-rule dark:border-[#2b3530] rounded-xl p-1 overflow-x-auto max-w-full">
                @foreach ([
                    ['insights',  'Model Insights'],
                    ['explorer',  'Cluster Explorer'],
                    ['snapshots', 'Snapshot History'],
                ] as [$key, $label])
                <button type="button"
                        @click="section = '{{ $key }}'"
                        :class="section === '{{ $key }}'
                            ? 'bg-forest-600 text-white shadow-sm'
                            : 'text-ink-600 dark:text-[#9aada5] hover:bg-white/60 dark:hover:bg-white/5'"
                        class="flex-shrink-0 px-4 py-2 text-[13px] font-semibold rounded-lg transition-colors duration-100 whitespace-nowrap">
                    {{ $label }}
                </button>
                @endforeach
            </div>
        </div>
```

This is intentionally distinct from the inner `.segmented` domain control (forest-filled
active vs. that control's white active), so the two read as different levels.

- [ ] **Step 4: Run test, verify it still PASSES**

Run: `php artisan test --filter health_groups_page_renders_for_admin`
Expected: PASS (page still renders, three labels present).

- [ ] **Step 5: Commit**

```bash
git add resources/views/reports/cluster.blade.php tests/Feature/Batch3HealthGroupsTest.php
git commit -m "feat(health-groups): prominent segmented section switcher"
```

---

## Task 2: Model Insights labels + bar redesign

**Files:**
- Modify: `resources/views/reports/cluster.blade.php` (`:186`, `:240-254`)
- Test: `tests/Feature/Batch3HealthGroupsTest.php`

- [ ] **Step 1: Write the failing test**

Append to `Batch3HealthGroupsTest`:

```php
    #[Test]
    public function model_insights_uses_corrected_who_domain_labels(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.cluster'))
            ->assertOk()
            ->assertSee('Intrinsic Capacity (IC)')
            ->assertSee('Functional Ability')
            ->assertDontSee('Daily Functioning')
            ->assertDontSee('Physical Capacity (IC)');
    }
```

- [ ] **Step 2: Run test, verify it FAILS**

Run: `php artisan test --filter model_insights_uses_corrected_who_domain_labels`
Expected: FAIL — the labels object still has `Physical Capacity (IC)` / `Daily Functioning`.

- [ ] **Step 3: Fix the domain labels**

In `resources/views/reports/cluster.blade.php`, the Alpine `labels` object (`:186`):
```blade
        labels: { ic: 'Physical Capacity (IC)', env: 'Environment', func: 'Daily Functioning' },
```
Replace with:
```blade
        labels: { ic: 'Intrinsic Capacity (IC)', env: 'Environment', func: 'Functional Ability' },
```

- [ ] **Step 4: Redesign the feature-importance bars**

In the same file, replace the populated-data template (`:240-254`, the
`<template x-if="insights && insights[insightsTab]">` block) with:

```blade
                <template x-if="insights && insights[insightsTab]">
                    <ol class="space-y-1">
                        <template x-for="(item, idx) in insights[insightsTab]" :key="item.feature">
                            <li class="flex items-center gap-3 rounded-lg px-2 -mx-2 py-1.5 hover:bg-paper-2 dark:hover:bg-[#131917] transition-colors">
                                <span class="w-5 text-right text-[11px] font-mono tnum text-ink-400 dark:text-[#6b7570] flex-shrink-0" x-text="idx + 1"></span>
                                <span class="text-[12.5px] text-ink-800 dark:text-[#c8c4bc] w-48 flex-shrink-0 truncate" x-text="item.label" :title="item.label"></span>
                                <div class="flex-1 bg-paper-rule dark:bg-[#222a27] rounded-full h-3 overflow-hidden">
                                    <div class="bg-forest-500 h-3 rounded-full transition-all duration-500"
                                         :style="'width: ' + Math.min(100, (item.importance / insights[insightsTab][0].importance) * 100) + '%'"></div>
                                </div>
                                <span class="text-[11.5px] font-mono tnum font-semibold text-forest-700 dark:text-forest-400 w-12 text-right"
                                      x-text="(item.importance * 100).toFixed(1) + '%'"></span>
                            </li>
                        </template>
                    </ol>
                </template>
```

Changes: numbered rank (`idx + 1`), wider feature labels (`w-48`), taller bars (`h-3`),
and an emphasized forest percentage. Do NOT change the loading skeleton
(`insights === null`) or the `insights === false` empty-state templates.

- [ ] **Step 5: Run test, verify it PASSES**

Run: `php artisan test --filter model_insights_uses_corrected_who_domain_labels`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add resources/views/reports/cluster.blade.php tests/Feature/Batch3HealthGroupsTest.php
git commit -m "feat(health-groups): redesign Model Insights bars + WHO domain labels"
```

---

## Task 3: Permanently delete cluster snapshots

**Files:**
- Modify: `routes/reports.php`
- Modify: `app/Http/Controllers/ReportController.php`
- Modify: `resources/views/reports/cluster.blade.php` (Snapshot History table `:271-312`)
- Test: `tests/Feature/Batch3HealthGroupsTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `Batch3HealthGroupsTest`:

```php
    #[Test]
    public function admin_can_permanently_delete_a_snapshot_by_date(): void
    {
        $date = now()->subDay()->toDateString();
        $this->makeSnapshotForDate($date);
        $this->assertSame(4, ClusterSnapshot::whereDate('snapshot_date', $date)->count());

        $this->actingAs($this->admin)
            ->delete(route('reports.cluster.snapshot.destroy', $date))
            ->assertRedirect();

        $this->assertSame(0, ClusterSnapshot::whereDate('snapshot_date', $date)->count());
    }

    #[Test]
    public function encoder_cannot_delete_a_snapshot(): void
    {
        $date = now()->subDay()->toDateString();
        $this->makeSnapshotForDate($date);

        $this->actingAs($this->encoder)
            ->delete(route('reports.cluster.snapshot.destroy', $date))
            ->assertForbidden();

        $this->assertSame(4, ClusterSnapshot::whereDate('snapshot_date', $date)->count());
    }

    #[Test]
    public function deleting_an_unknown_snapshot_date_reports_no_match(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('reports.cluster.snapshot.destroy', '2099-01-01'))
            ->assertRedirect();
    }
```

- [ ] **Step 2: Run tests, verify they FAIL**

Run: `php artisan test --filter Batch3HealthGroups`
Expected: FAIL — `reports.cluster.snapshot.destroy` route does not exist (RouteNotFoundException).

- [ ] **Step 3: Add the admin-only delete route**

In `routes/reports.php`, inside the existing `Route::middleware('role:admin')->group(...)`
block (which already contains `cluster.snapshot`), add:

```php
        Route::delete('/cluster/snapshot/{date}', [ReportController::class, 'destroySnapshot'])->name('cluster.snapshot.destroy');
```

- [ ] **Step 4: Add the controller method**

In `app/Http/Controllers/ReportController.php`, immediately after the existing
`snapshotClusters()` method, add:

```php
    /**
     * Permanently delete all cluster-snapshot rows for a given date (Y-m-d).
     * ClusterSnapshot has no soft-deletes, so this is irreversible. Admin only.
     */
    public function destroySnapshot(string $date)
    {
        try {
            $parsed = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        } catch (\Throwable $e) {
            abort(404);
        }

        $deleted = ClusterSnapshot::whereDate('snapshot_date', $parsed->toDateString())->delete();

        return back()->with(
            $deleted ? 'success' : 'error',
            $deleted
                ? "Snapshot for {$parsed->format('M d, Y')} permanently deleted."
                : 'No snapshot found for that date.'
        );
    }
```

`ClusterSnapshot` is already imported at the top of the controller (used by
`snapshotClusters`/`cluster`). Confirm before relying on it.

- [ ] **Step 5: Run tests, verify they PASS**

Run: `php artisan test --filter Batch3HealthGroups`
Expected: PASS (admin deletes, encoder forbidden, unknown-date redirects).

- [ ] **Step 6: Add the Delete UI to the Snapshot History table**

In `resources/views/reports/cluster.blade.php`, add an Actions column to the snapshot
table. First, in the `<thead>` row (`:275-280`), add a final header cell after the Total
`<th>`:
```blade
                            <th class="px-4 py-2.5 text-right font-semibold text-ink-600 dark:text-[#6b7d76]">Actions</th>
```
Then, in the `<tbody>` row, after the Total `<td>` (`:307`), add (only for admins):
```blade
                            <td class="px-4 py-2.5 text-right">
                                @if (auth()->user()?->hasRole('admin'))
                                <div x-data="{ open: false }" class="inline-block">
                                    <button type="button" @click="open = true"
                                            class="btn btn-ghost text-[11px] px-2 py-1 text-critical-700 hover:bg-critical-50 hover:text-critical-900">
                                        Delete
                                    </button>
                                    <form x-ref="delForm" method="POST" action="{{ route('reports.cluster.snapshot.destroy', $date) }}" class="hidden">
                                        @csrf @method('DELETE')
                                    </form>
                                    <x-confirm-modal show="open"
                                                     title="Delete snapshot?"
                                                     confirm="$refs.delForm.submit()"
                                                     confirm-label="Delete permanently">
                                        <p>The cluster snapshot from <strong class="text-ink-900 dark:text-[#e4e1d8]">{{ \Carbon\Carbon::parse($date)->format('M d, Y') }}</strong> will be permanently removed.</p>
                                        <p class="mt-2 text-[12px] font-semibold px-3 py-2 rounded-xl text-critical-700 dark:text-[#e08070] bg-critical-50 dark:bg-critical-50/10 border border-critical-100 dark:border-critical-700/30">
                                            This cannot be undone.
                                        </p>
                                    </x-confirm-modal>
                                </div>
                                @endif
                            </td>
```

- [ ] **Step 7: Verify the page still renders and tests pass**

Run: `php artisan test --filter Batch3HealthGroups`
Expected: PASS (all 5 tests). Then load `/reports/cluster` → Snapshot History tab as
admin and confirm the Delete button + confirm modal appear and work.

- [ ] **Step 8: Commit**

```bash
git add routes/reports.php app/Http/Controllers/ReportController.php resources/views/reports/cluster.blade.php tests/Feature/Batch3HealthGroupsTest.php
git commit -m "feat(health-groups): permanently delete cluster snapshots (admin-only)"
```

---

## Task 4: Full-suite verification + Pint

- [ ] **Step 1: Run the batch test file**

Run: `php artisan test --filter Batch3HealthGroups`
Expected: PASS (5 tests).

- [ ] **Step 2: Run the full suite**

Run: `php artisan test`
Expected: PASS, no regressions.

- [ ] **Step 3: Pint the changed files (avoid the CI php-checks failure)**

Run: `./vendor/bin/pint app/Http/Controllers/ReportController.php routes/reports.php tests/Feature/Batch3HealthGroupsTest.php`
Then verify: `./vendor/bin/pint --test app/Http/Controllers/ReportController.php routes/reports.php tests/Feature/Batch3HealthGroupsTest.php`
Expected: result "passed". (Do NOT run bare `./vendor/bin/pint` — on this Windows checkout it reports CRLF line-ending diffs on ~120 unrelated files that are not violations on CI's Linux runner. Scope Pint to changed files.) Commit any Pint fixes:
```bash
git add -A && git commit -m "style: pint" || echo "nothing to fix"
```

- [ ] **Step 4: Manual smoke checklist**

- Section switcher reads as an obvious pill group; active section is forest-filled; switching works.
- Model Insights: ranked bars, corrected WHO labels, domain switch works.
- Snapshot History (as admin): Delete button + confirm modal; deleting removes that date's row permanently. As encoder/viewer, no Delete button and the route is forbidden.

---

## Self-Review Notes

- **Spec coverage:** Req 1 (section switcher) → Task 1; Req 2 (insights labels + bars) → Task 2; Req 3 (snapshot delete: route + controller + UI) → Task 3. All covered.
- **Name/route consistency:** route name `reports.cluster.snapshot.destroy` used identically in route definition, controller `back()`, view form, and all three tests. `destroySnapshot(string $date)` signature matches the `{date}` route param. `makeSnapshotForDate()` helper defined in Task 1 setup area, reused in Task 3.
- **No placeholders:** every code step shows concrete content.
- **Pint:** Task 4 scopes Pint to changed PHP files per the project's CI gate.
