# QA Batch 6 — Admin & Misc

**Date:** 2026-06-05
**Status:** Approved design, pending implementation plan
**Source:** QA Testing punch list (June 4, 2026), Batch 6 of 6 (final)

## Context

Sixth and final module-scoped batch from the 2026-06-04 QA punch list. Covers four
small admin/misc fixes: Batch Analysis search, the Activity Log "Delete Selected"
bug, an Export Registry landing page, and a User Management password eye-toggle.

Batches 1-5 are merged (PRs #74-#78).

## Affected code (current state)

- `app/Http/Controllers/MlController.php` `batchIndex()` (`:64-80`) — builds `$pending`
  (eligible seniors with a QoL survey), `->paginate(25)`; no search; no Request param.
- `resources/views/ml/batch.blade.php` — run panel + a paginated table of `$pending`
  (`:203+`); no search input.
- `resources/views/activity_log/index.blade.php` — Alpine selection (`:55-64`:
  `selected`, `allIds` getter over `.row-cb`, `toggle`/`toggleAll`); row checkboxes
  (`:98-101`); a floating action bar (`:137-164`, `x-show="selected.length > 0"`,
  `bg-ink-900 text-white`) whose **Delete Selected** button uses a native `confirm()`
  inside `@click.prevent` with escaped-backtick JS, submitting a form of hidden
  `ids[]` inputs to `activity-log.bulk-destroy`.
- `app/Http/Controllers/ActivityLogController.php` `bulkDestroy()` (`:24`) — works and is
  covered by `tests/Feature/ActivityLogDeleteTest.php` (admin deletes selected; encoder
  forbidden). The defect is frontend only.
- `app/Http/Controllers/ReportController.php` `exportRegistry()` — builds the registry and
  streams an XLSX download directly.
- `routes/reports.php` — `registry.export` (`:26`) in the admin-only group.
- `resources/views/layouts/app.blade.php` (`:185-190`) — sidebar "Export Registry" link
  pointing straight at `reports.registry.export` (immediate download).
- `resources/views/users/create.blade.php` (`:62-74`) — Password (`:63`) and Confirm
  Password (`:74`) inputs, both `type="password"`, no show/hide toggle.
- Reusable: `<x-confirm-modal>` (Alpine, used by every other delete), `kpi` cards,
  `form-input`, heroicon eye / eye-slash.

## Requirements

### 1. Batch Analysis — search

Add a server-side search (senior name OR OSCA ID) to the eligible-seniors table.

- `batchIndex(Request $request)`: add a `when($request->search, …)` clause to the
  `$pending` query (`whereHas`/`where` on osca_id + name), preserving the existing
  pagination; use `->withQueryString()` on the paginator.
- `ml/batch.blade.php`: add a search input (GET form) above the table; show a Clear link
  when a search is active; pagination links preserve the query.

### 2. Activity Log — fix "Delete Selected"

The backend is correct; fix the frontend. Confirm the exact root cause by reproduction
(systematic-debugging) before finalizing, then:

- **Visibility:** ensure the floating action bar and its Delete button are clearly
  visible in light mode (sufficient contrast; the bar must actually appear when rows are
  selected).
- **Working submit:** replace the fragile native `confirm()` + escaped-backtick
  `@click.prevent` handler with the project's standard `<x-confirm-modal>` flow (Alpine
  `x-data="{ open: false }"` → confirm → submit the hidden-`ids[]` form). After
  confirming, the selected IDs must POST to `activity-log.bulk-destroy` and be deleted.
- Keep the existing selection mechanism (`selected`, row checkboxes, "select all",
  "Deselect all") working; only the confirm/submit and styling change.

### 3. Export Registry — landing page

Replace the immediate-download sidebar link with an admin-only landing page.

- New route `reports.registry` (GET, admin-only group) → `ReportController@registryIndex`.
- `registryIndex()` computes preview data: summary counts (total active seniors, assessed
  vs. not assessed, risk-level breakdown, barangays covered) and a small sample of the
  registry (first ~12 rows: OSCA ID, name, barangay, risk level, cluster).
- New view `resources/views/reports/registry.blade.php`: KPI summary cards + the preview
  table + a prominent **Download Full Registry (XLSX)** button linking to
  `reports.registry.export`.
- `layouts/app.blade.php`: the sidebar "Export Registry" link now points to
  `reports.registry` (the landing page), not the direct export.
- `exportRegistry()` and `registry.export` are unchanged (the download still works).

### 4. User Management — password eye toggle

Add a show/hide toggle to both password fields in `users/create.blade.php`.

- Wrap each password field in an Alpine `x-data="{ show: false }"`; the input `type` binds
  to `show ? 'text' : 'password'`; an eye / eye-slash icon button inside the field toggles
  `show`. Apply to Password and Confirm Password.
- Preserve the existing `@error` styling, name attributes, and validation.

## Design decisions

- **Export Registry landing = KPI summary + ~12-row preview table + Download button**,
  admin-only. (User-confirmed.)
- **Activity Log delete switches to the standard `<x-confirm-modal>`** (replacing native
  `confirm()`), plus light-mode visibility fix. (User-confirmed.)
- **Search = name + OSCA ID**, server-side, matching Batches 1/2/5.
- **Password toggle** uses Alpine type-switching, no new dependencies.

## Out of scope

- The Batch Analysis run pipeline, ML logic, and the registry export query/format are
  unchanged.
- This is the final batch; no further QA punch-list items remain after it.

## Verification

- Batch Analysis: search by name/OSCA narrows the table; pagination preserves the query.
- Activity Log: selecting rows shows a clearly-visible bar in light mode; Delete Selected
  opens the confirm modal and actually deletes the selected entries (admin); encoder still
  forbidden.
- Export Registry: the sidebar link opens a landing page with summary cards + a preview
  table; the Download button produces the XLSX; the page is admin-only.
- User Management: the eye toggles reveal/hide each password field.
- `php artisan test` passes (new tests cover batch search, the registry landing route +
  admin gating; Activity Log delete remains green); `./vendor/bin/pint` clean on changed
  PHP before push.
