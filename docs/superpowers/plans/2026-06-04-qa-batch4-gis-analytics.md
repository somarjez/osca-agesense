# QA Batch 4 — GIS Analytics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the bulk-geocode browser `confirm()` with an in-app modal, compute accessibility only in Markers + Accessibility modes, rebalance the Risk Distribution heatmap so risk severity (not population density) drives the colors, and confirm the cluster filter shows full labels.

**Architecture:** All changes are in the GIS map view `resources/views/reports/gis.blade.php` (Leaflet + ~4800 lines of inline JS). The geocode confirmation reuses `<x-confirm-modal>`. Mode gating and risk weighting are inline-JS edits; the cluster labels are already wired and only need verification. Most behavior is client-side and verified manually; a PHPUnit feature test guards the Blade-rendered parts.

**Tech Stack:** PHP 8.2, Laravel, Blade, Alpine.js, Leaflet, PHPUnit. GIS module skill: `.claude/skills/gis-module/`.

**Spec:** `docs/superpowers/specs/2026-06-04-qa-batch4-gis-analytics-design.md`

**Note on testing:** Tasks 2 and 3 change runtime canvas/Leaflet behavior that PHPUnit cannot assert; their automated coverage is a page-renders-200 smoke, and correctness is verified manually on the map (see Task 5). This is expected, not a gap.

---

## File Structure

- `resources/views/reports/gis.blade.php` — geocode modal (Task 1), accessibility mode gating (Task 2), risk weighting (Task 3), cluster-label verification (Task 4).
- `tests/Feature/Batch4GisAnalyticsTest.php` — feature tests for the Blade-renderable parts (Tasks 1, 4) + render smoke.

---

## Task 1: Bulk geocode → in-app modal

**Files:**
- Modify: `resources/views/reports/gis.blade.php` (`:48-57`)
- Test: `tests/Feature/Batch4GisAnalyticsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Batch4GisAnalyticsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class Batch4GisAnalyticsTest extends TestCase
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

    #[Test]
    public function gis_geocode_uses_modal_not_native_confirm(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.gis'))
            ->assertOk()
            ->assertDontSee("onsubmit=\"return confirm('Run bulk", false)
            ->assertSee('Run bulk geocoding?');
    }
}
```

- [ ] **Step 2: Run test, verify it FAILS**

Run: `php artisan test --filter gis_geocode_uses_modal_not_native_confirm`
Expected: FAIL — the page still has the `onsubmit="return confirm('Run bulk …"` handler and no "Run bulk geocoding?" modal title.

If the GIS page errors instead of rendering (missing data), READ the `gis()` method in `app/Http/Controllers/ReportController.php` and report what data it needs — the seeded test DB should let it render.

- [ ] **Step 3: Replace the confirm() form with a modal**

In `resources/views/reports/gis.blade.php`, replace the `@role('admin') … @endrole` geocode form block (`:48-57`):
```blade
                @role('admin')
                <form method="POST" action="{{ route('reports.gis.geocode') }}"
                      class="shrink-0 sm:ml-auto"
                      onsubmit="return confirm('Run bulk barangay-level geocoding now? This will not overwrite verified manual/GPS coordinates.');">
                    @csrf
                    <button type="submit" class="btn text-[12px] px-3 py-2 whitespace-nowrap">
                        Run Bulk Geocode
                    </button>
                </form>
                @endrole
```
with:
```blade
                @role('admin')
                <div x-data="{ open: false }" class="shrink-0 sm:ml-auto">
                    <button type="button" @click="open = true" class="btn text-[12px] px-3 py-2 whitespace-nowrap">
                        Run Bulk Geocode
                    </button>
                    <form x-ref="geocodeForm" method="POST" action="{{ route('reports.gis.geocode') }}" class="hidden">
                        @csrf
                    </form>
                    <x-confirm-modal show="open"
                                     title="Run bulk geocoding?"
                                     tone="primary"
                                     confirm="$refs.geocodeForm.submit()"
                                     confirm-label="Run geocoding">
                        <p>This assigns approximate barangay-level coordinates to seniors without coordinates so they can be mapped for planning. It will <strong class="text-ink-900 dark:text-[#e4e1d8]">not</strong> overwrite verified manual or GPS-captured pins.</p>
                    </x-confirm-modal>
                </div>
                @endrole
```

- [ ] **Step 4: Run test, verify it PASSES**

Run: `php artisan test --filter gis_geocode_uses_modal_not_native_confirm`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/views/reports/gis.blade.php tests/Feature/Batch4GisAnalyticsTest.php
git commit -m "feat(gis): replace bulk-geocode confirm() with in-app modal"
```

---

## Task 2: Compute accessibility only in Markers + Accessibility modes

The senior popup's "Nearby senior services" section triggers ORS route-distance
computation on `popupopen`. Gate both the section and the computation so they appear only
in `markers` and `senior-distribution-accessibility-heatmap` modes.

**Files:**
- Modify: `resources/views/reports/gis.blade.php` (`popupHtml` `:3687-3727`, `updateRoadNetworkServices` `:3585`)

- [ ] **Step 1: Add a mode helper**

In `resources/views/reports/gis.blade.php`, immediately BEFORE the `popupHtml` function
(`:3674`), add:
```blade
    function accessibilityComputationEnabled() {
        const mode = document.getElementById(MODE_ID)?.value ?? 'markers';
        return mode === 'markers' || mode === 'senior-distribution-accessibility-heatmap';
    }
```

- [ ] **Step 2: Gate the popup accessibility + services section**

In `popupHtml` (`:3687-3694`), the current code is:
```blade
        const popupMode = document.getElementById(MODE_ID)?.value ?? 'markers';
        const accessibilityRow = popupMode === 'risk-indicator-heatmap'
            ? ''
            : `<div><strong>Accessibility Status:</strong> ${accessibility}</div>`;
        const services = routedServices
            ? serviceListHtml(routedServices)
            : routeLoadingListHtml(routeCandidatesForFeature(feature));
        const servicesElementId = escapeHtml(routeServicesElementId(feature));
```
Replace with:
```blade
        const showAccess = accessibilityComputationEnabled();
        const accessibilityRow = showAccess
            ? `<div><strong>Accessibility Status:</strong> ${accessibility}</div>`
            : '';
        const services = routedServices
            ? serviceListHtml(routedServices)
            : routeLoadingListHtml(routeCandidatesForFeature(feature));
        const servicesElementId = escapeHtml(routeServicesElementId(feature));
        const servicesBlock = showAccess
            ? `<div><strong>Nearby senior services:</strong><div id="${servicesElementId}">${services}</div></div>`
            : '';
```
Then, in BOTH popup return templates (the `is_generalized_senior_point` block `:3696-3711` and the default block `:3714-3728`), replace this fragment:
```blade
                    ${accessibilityRow}
                    <div>
                        <strong>Nearby senior services:</strong>
                        <div id="${servicesElementId}">${services}</div>
                    </div>
```
(and the equivalently-indented copy in the second template) with:
```blade
                    ${accessibilityRow}
                    ${servicesBlock}
```

- [ ] **Step 3: Guard the ORS computation**

In `updateRoadNetworkServices` (`:3585`), add an early return at the very top of the
function body (right after `const popup = layer.getPopup?.();` / `if (!popup) return;`):
```blade
        if (!accessibilityComputationEnabled()) return;
```
This prevents any ORS/OSRM `roadRouteDistance` calls when not in an accessibility mode.

- [ ] **Step 4: Render-smoke test**

Run: `php artisan test --filter Batch4GisAnalytics`
Expected: PASS (page still renders; no regressions). Behavior is verified manually in Task 5.

- [ ] **Step 5: Commit**

```bash
git add resources/views/reports/gis.blade.php
git commit -m "feat(gis): compute accessibility only in Markers and Accessibility modes"
```

---

## Task 3: Risk Distribution heatmap reflects severity, not density

Rebalance `riskWeight` so HIGH-risk points dominate the KDE surface and dense low-risk
areas stay cool.

**Files:**
- Modify: `resources/views/reports/gis.blade.php` (`riskWeight` `:607-618`)

- [ ] **Step 1: Widen the risk tier separation**

In `resources/views/reports/gis.blade.php`, replace `riskWeight` (`:607-618`):
```blade
    function riskWeight(level) {
        switch ((level || '').toUpperCase()) {
            case 'HIGH':
                return 1.0;
            case 'MODERATE':
                return 0.6;
            case 'LOW':
                return 0.3;
            default:
                return null;
        }
    }
```
with:
```blade
    function riskWeight(level) {
        // Widen tier separation so the KDE surface reflects risk SEVERITY rather than
        // population density: a dense cluster of LOW-risk seniors should not out-weigh a
        // few HIGH-risk seniors. HIGH dominates; LOW contributes only a faint floor.
        switch ((level || '').toUpperCase()) {
            case 'HIGH':
                return 1.0;
            case 'MODERATE':
                return 0.4;
            case 'LOW':
                return 0.12;
            default:
                return null;
        }
    }
```

- [ ] **Step 2: Render-smoke test**

Run: `php artisan test --filter Batch4GisAnalytics`
Expected: PASS. The visual effect (high-risk areas hottest, dense low-risk areas green) is
verified manually in Task 5 — tune the three constants by eye if the separation still
reads weak, keeping HIGH=1.0 as the ceiling and LOW near the floor.

- [ ] **Step 3: Commit**

```bash
git add resources/views/reports/gis.blade.php
git commit -m "fix(gis): risk heatmap weights reflect severity not population density"
```

---

## Task 4: Verify cluster filter shows full labels (already wired)

The cluster filter already maps option labels to `CLUSTER_HEATMAP_RAMPS[n].title`
(`gis.blade.php:905-908`), so the four full "C{n} · …" titles should already display.
This task confirms it with a regression guard — no code change expected.

**Files:**
- Test: `tests/Feature/Batch4GisAnalyticsTest.php`

- [ ] **Step 1: Add a guard test**

Append to `Batch4GisAnalyticsTest`:
```php
    #[Test]
    public function cluster_filter_labels_use_full_cluster_titles(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.gis'))
            ->assertOk()
            ->assertSee('C1 · High Functioning / Well-Supported Seniors')
            ->assertSee('C2 · Stable Ageing / Moderate Support Needs')
            ->assertSee('C3 · Environmentally and Financially Vulnerable Seniors')
            ->assertSee('C4 · Low Functioning / Multi-Domain Priority Seniors');
    }
```
(These titles live in `CLUSTER_HEATMAP_RAMPS` in the page's inline JS, and the filter
population at `:905-908` uses them as the option display text.)

- [ ] **Step 2: Run the test**

Run: `php artisan test --filter cluster_filter_labels_use_full_cluster_titles`
Expected: PASS (titles present). If it FAILS, the titles are missing/renamed — only then
adjust `CLUSTER_HEATMAP_RAMPS`/the `:905-908` mapping and re-run. Report whichever case.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Batch4GisAnalyticsTest.php
git commit -m "test(gis): guard cluster filter full-title labels"
```

---

## Task 5: Verification (automated + manual)

- [ ] **Step 1: Batch test file**

Run: `php artisan test --filter Batch4GisAnalytics`
Expected: PASS (geocode modal, cluster titles, render smoke).

- [ ] **Step 2: Full suite**

Run: `php artisan test`
Expected: PASS, no regressions.

- [ ] **Step 3: Pint the changed PHP/test files (CI gate)**

Run: `./vendor/bin/pint tests/Feature/Batch4GisAnalyticsTest.php`
Then verify: `./vendor/bin/pint --test tests/Feature/Batch4GisAnalyticsTest.php`
Expected: "passed". (Scope Pint to changed files — do NOT run bare `./vendor/bin/pint`; on this Windows checkout it reports CRLF line-ending diffs on ~120 unrelated files that are not violations on CI's Linux runner.) Commit any fix:
```bash
git add -A && git commit -m "style: pint" || echo "nothing to fix"
```

- [ ] **Step 4: Manual map verification (required — JS/runtime)**

Load `/reports/gis` as admin and confirm:
- **Geocode:** "Run Bulk Geocode" opens an in-app modal (no native browser dialog); confirming runs geocoding.
- **Mode gating:** in **Risk Indicator Distribution** and **Cluster / Health Groups Heatmap**, clicking a senior shows a popup WITHOUT the "Accessibility Status" / "Nearby senior services" section and makes no `/api/gis/route-distance` request (check the Network tab). In **Senior Population Overview** and **Accessibility Heatmap**, the popup still shows and computes route distances.
- **Risk heatmap:** Risk Indicator Distribution clearly highlights high-risk areas; a dense low-risk barangay reads green, not red. Tune `riskWeight` constants if needed.
- **Cluster dropdown:** the "Cluster / Health Group" filter lists the four full "C{n} · …" titles; selecting one filters the map; "All Groups" works.
- Optional: run `.claude/skills/run-osca-system/smoke.ps1` to confirm the GIS API still serves data.

---

## Self-Review Notes

- **Spec coverage:** Req 1 (geocode modal) → Task 1; Req 2 (accessibility gating) → Task 2; Req 3 (risk weighting) → Task 3; Req 4 (cluster labels) → Task 4 (verify-only, already wired). All covered.
- **Name consistency:** `accessibilityComputationEnabled()` defined in Task 2 Step 1 and used in Steps 2-3; `MODE_ID` is the existing constant for `gis-visualization-mode`; `servicesBlock` introduced and consumed within the same `popupHtml`. Mode strings (`markers`, `senior-distribution-accessibility-heatmap`, `risk-indicator-heatmap`, `cluster-heatmap`) match the `<option value>`s at `:106-111`.
- **No placeholders:** every code step shows concrete content.
- **Testing honesty:** Tasks 2-3 are runtime-JS; their automated step is the render smoke, with explicit manual verification in Task 5.
- **Pint:** Task 5 scopes Pint to the changed test file (only PHP file changed; the rest are Blade/JS).
