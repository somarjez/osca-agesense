# Export Age Fix · Batch Last-Run Timestamp · Activity Log Delete — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix age = 0 in all CSV/Excel exports; display when batch analysis was last started; allow admins to bulk-delete and clear the activity log.

**Architecture:** Three independent changes — a one-line model accessor fix, two cache writes + a view line for batch timestamp, and two new controller methods + routes + view additions for activity log deletion. No migrations. No new models.

**Tech Stack:** Laravel 11, PHPUnit (via `php artisan test`), Eloquent, Alpine.js, Blade, `spatie/laravel-permission`, `Illuminate\Support\Facades\Cache`, `Illuminate\Support\Facades\Bus`

---

## File Map

| File | Action | What changes |
|---|---|---|
| `app/Models/SeniorCitizen.php` | Modify | `getAgeAttribute()` checks raw `attributes['age']` first |
| `tests/Feature/ExportTest.php` | Modify | Age-value assertions added to cluster + risk CSV tests |
| `app/Http/Controllers/MlController.php` | Modify | `batchRun()` writes 2 cache keys; `batchIndex()` passes them to view |
| `resources/views/ml/batch.blade.php` | Modify | "Last run" metadata line in the run card |
| `tests/Feature/BatchTimestampTest.php` | Create | Tests for cache read/write behaviour |
| `routes/web.php` | Modify | Activity-log single route → route group + 2 DELETE routes |
| `app/Http/Controllers/ActivityLogController.php` | Modify | Add `bulkDestroy()` and `clear()` |
| `tests/Feature/ActivityLogDeleteTest.php` | Create | Tests for `bulkDestroy`, `clear`, and auth guard |
| `resources/views/activity_log/index.blade.php` | Modify | Clear All button, checkbox column, floating action bar |

---

## Task 1: Fix age = 0 in CSV exports

**Files:**
- Modify: `tests/Feature/ExportTest.php`
- Modify: `app/Models/SeniorCitizen.php:76-79`

---

- [ ] **Step 1.1 — Add failing age assertions to the cluster CSV test**

  Open `tests/Feature/ExportTest.php`. Inside `cluster_csv_export_returns_csv_with_correct_headers()`, add these assertions immediately after the existing `assertGreaterThan(1, count($lines))` line:

  ```php
  // Age column must never be 0 for a senior born in 1950.
  // Column order in cluster CSV: OSCA ID, Name, Barangay, Age, Gender, …
  $dataRow = str_getcsv($lines[1]);   // first data row (index 0 = header)
  $ageValue = (int) $dataRow[3];      // Age is the 4th column (0-indexed: 3)
  $this->assertGreaterThan(0, $ageValue,
      "Age column in cluster CSV is 0 — getAgeAttribute accessor bug.");
  ```

- [ ] **Step 1.2 — Add failing age assertions to the risk CSV test**

  Inside `risk_csv_export_returns_csv_with_correct_headers()`, add after the existing per-line HIGH assertion loop:

  ```php
  // Age must be non-zero in the risk CSV as well.
  // Column order in risk CSV: OSCA ID, Name, Barangay, Age, Risk Level, …
  $firstDataRow = str_getcsv($lines[1]);
  $ageValue = (int) $firstDataRow[3];
  $this->assertGreaterThan(0, $ageValue,
      "Age column in risk CSV is 0 — getAgeAttribute accessor bug.");
  ```

- [ ] **Step 1.3 — Run to confirm both new assertions fail**

  ```
  php artisan test --filter=ExportTest
  ```

  Expected: `cluster_csv_export_returns_csv_with_correct_headers` and `risk_csv_export_returns_csv_with_correct_headers` both **FAIL** with "Age column … is 0".

- [ ] **Step 1.4 — Fix `getAgeAttribute()` in `SeniorCitizen`**

  Open `app/Models/SeniorCitizen.php`. Replace lines 76–79:

  ```php
  // Before:
  public function getAgeAttribute(): int
  {
      return $this->date_of_birth?->diffInYears(now()) ?? 0;
  }
  ```

  ```php
  // After:
  public function getAgeAttribute(): int
  {
      // Export queries compute age via SQL (TIMESTAMPDIFF) and alias it as 'age'.
      // When that SQL value is present, use it directly — date_of_birth is not
      // selected in those queries, so the Carbon fallback would return 0.
      if (array_key_exists('age', $this->attributes)) {
          return (int) $this->attributes['age'];
      }
      // Full model loads (profile page, PDF, show view) use the Carbon path.
      return $this->date_of_birth?->diffInYears(now()) ?? 0;
  }
  ```

- [ ] **Step 1.5 — Run to confirm all export tests pass**

  ```
  php artisan test --filter=ExportTest
  ```

  Expected: all tests **PASS**. No regressions.

- [ ] **Step 1.6 — Commit**

  ```
  git add app/Models/SeniorCitizen.php tests/Feature/ExportTest.php
  git commit -m "fix: age accessor uses SQL-computed value in export queries

  getAgeAttribute() previously returned 0 in exportCluster() and exportRisk()
  because those queries select TIMESTAMPDIFF(...) as 'age' but do NOT select
  date_of_birth — leaving $this->date_of_birth null at accessor call time.

  Fix: check $this->attributes['age'] first; fall back to Carbon only when the
  SQL column is absent (profile page, PDF, individual show view)."
  ```

---

## Task 2: Batch analysis last-run timestamp — controller + tests

**Files:**
- Create: `tests/Feature/BatchTimestampTest.php`
- Modify: `app/Http/Controllers/MlController.php`

---

- [ ] **Step 2.1 — Create `tests/Feature/BatchTimestampTest.php`**

  ```php
  <?php

  namespace Tests\Feature;

  use App\Models\SeniorCitizen;
  use App\Models\User;
  use Illuminate\Foundation\Testing\DatabaseTransactions;
  use Illuminate\Support\Facades\Bus;
  use Illuminate\Support\Facades\Cache;
  use Illuminate\Support\Facades\Hash;
  use PHPUnit\Framework\Attributes\Test;
  use Spatie\Permission\Models\Role;
  use Spatie\Permission\PermissionRegistrar;
  use Tests\TestCase;

  class BatchTimestampTest extends TestCase
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

      #[Test]
      public function batch_index_passes_null_when_cache_is_empty(): void
      {
          Cache::forget('ml_last_batch_started');
          Cache::forget('ml_last_batch_senior_count');

          $response = $this->actingAs($this->admin)->get(route('ml.batch'));

          $response->assertOk();
          $response->assertViewHas('lastBatchRun', null);
          $response->assertViewHas('lastBatchCount', null);
      }

      #[Test]
      public function batch_index_passes_cached_timestamp_to_view(): void
      {
          $timestamp = now()->subHours(3);
          Cache::put('ml_last_batch_started',      $timestamp, now()->addDays(90));
          Cache::put('ml_last_batch_senior_count', 283,        now()->addDays(90));

          $response = $this->actingAs($this->admin)->get(route('ml.batch'));

          $response->assertOk();
          $response->assertViewHas('lastBatchRun',   $timestamp);
          $response->assertViewHas('lastBatchCount', 283);
      }

      #[Test]
      public function batch_run_stores_start_timestamp_in_cache(): void
      {
          Cache::forget('ml_last_batch_started');

          Bus::fake();

          // The seeded database has seniors with processed QoL surveys.
          // DatabaseTransactions wraps this test, so no records are modified.
          $response = $this->actingAs($this->admin)
              ->postJson(route('ml.batch.run'));

          // If no eligible seniors exist the endpoint returns 422 — skip assertion.
          if ($response->status() === 422) {
              $this->markTestSkipped('No eligible seniors in test DB — seed first.');
          }

          $response->assertOk();
          $this->assertNotNull(Cache::get('ml_last_batch_started'),
              'ml_last_batch_started was not written to cache after batchRun().');
          $this->assertGreaterThan(0, (int) Cache::get('ml_last_batch_senior_count'),
              'ml_last_batch_senior_count was not written or is zero.');
      }
  }
  ```

- [ ] **Step 2.2 — Run to confirm all three tests fail**

  ```
  php artisan test --filter=BatchTimestampTest
  ```

  Expected:
  - `batch_index_passes_null_when_cache_is_empty` — **FAIL** (`lastBatchRun` key not found in view data)
  - `batch_index_passes_cached_timestamp_to_view` — **FAIL** (same)
  - `batch_run_stores_start_timestamp_in_cache` — **FAIL** or SKIP (cache key absent)

- [ ] **Step 2.3 — Update `batchRun()` to write the start timestamp**

  Open `app/Http/Controllers/MlController.php`. In `batchRun()`, add two `Cache::put()` calls immediately after the four existing ones (after line ~105):

  ```php
  Cache::put("{$cacheKey}:batch_id",  $batch->id,        now()->addHours(2));
  Cache::put("{$cacheKey}:total",     count($seniorIds), now()->addHours(2));
  Cache::put("{$cacheKey}:processed", 0,                 now()->addHours(2));
  Cache::put("{$cacheKey}:failed",    0,                 now()->addHours(2));

  // NEW — record when the last full batch was started, shown on the batch page.
  Cache::put('ml_last_batch_started',      now(),              now()->addDays(90));
  Cache::put('ml_last_batch_senior_count', count($seniorIds),  now()->addDays(90));
  ```

- [ ] **Step 2.4 — Update `batchIndex()` to pass values to view**

  In the same file, replace the `return view(...)` line in `batchIndex()`:

  ```php
  // Before:
  return view('ml.batch', compact('pending', 'totalEligible'));

  // After:
  return view('ml.batch', compact('pending', 'totalEligible'))
      ->with('lastBatchRun',   Cache::get('ml_last_batch_started'))
      ->with('lastBatchCount', Cache::get('ml_last_batch_senior_count'));
  ```

  Add the `Cache` import at the top of the file if not already present:
  ```php
  use Illuminate\Support\Facades\Cache;
  ```
  *(It is already imported — the existing `batchRun()` uses it.)*

- [ ] **Step 2.5 — Run to confirm all three tests pass**

  ```
  php artisan test --filter=BatchTimestampTest
  ```

  Expected: all **PASS** (or the third is SKIP if seed data is unavailable).

- [ ] **Step 2.6 — Commit**

  ```
  git add app/Http/Controllers/MlController.php tests/Feature/BatchTimestampTest.php
  git commit -m "feat: record and display last batch analysis start time

  batchRun() now caches ml_last_batch_started and ml_last_batch_senior_count
  with a 90-day TTL. batchIndex() passes both to the batch view so staff
  can see when the last full batch was triggered."
  ```

---

## Task 3: Batch analysis last-run timestamp — view

**Files:**
- Modify: `resources/views/ml/batch.blade.php`

---

- [ ] **Step 3.1 — Add the "Last run" metadata line to the batch card**

  Open `resources/views/ml/batch.blade.php`. Find the paragraph that reads:

  ```blade
  <p class="text-sm text-ink-500 mb-3">Assesses all seniors with a QoL survey: prepares data → assigns health group → scores risk → generates recommendations.</p>
  ```

  Add the following paragraph **directly after** it (before the result/error banners block):

  ```blade
  <p class="text-xs text-ink-400 mt-1 mb-3">
      Last run:
      @if ($lastBatchRun)
          {{ $lastBatchRun->format('d M Y, g:i A') }}
          &middot; {{ $lastBatchCount }} senior(s)
      @else
          <span class="text-ink-300">Never run on this machine</span>
      @endif
  </p>
  ```

- [ ] **Step 3.2 — Smoke-test in the browser**

  The system should already be running (`start.bat`). Navigate to `http://127.0.0.1:8000/ml/batch`.

  - Before any batch has been run: should read **"Never run on this machine"**
  - After clicking "Run Full Batch" and waiting for it to finish: refresh the page — should read the date/time and senior count.

- [ ] **Step 3.3 — Commit**

  ```
  git add resources/views/ml/batch.blade.php
  git commit -m "feat: show last batch run timestamp on batch page"
  ```

---

## Task 4: Activity log delete — routes + controller + tests

**Files:**
- Create: `tests/Feature/ActivityLogDeleteTest.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/ActivityLogController.php`

---

- [ ] **Step 4.1 — Create `tests/Feature/ActivityLogDeleteTest.php`**

  ```php
  <?php

  namespace Tests\Feature;

  use App\Models\ActivityLog;
  use App\Models\SeniorCitizen;
  use App\Models\User;
  use Illuminate\Foundation\Testing\DatabaseTransactions;
  use Illuminate\Support\Facades\Hash;
  use PHPUnit\Framework\Attributes\Test;
  use Spatie\Permission\Models\Role;
  use Spatie\Permission\PermissionRegistrar;
  use Tests\TestCase;

  class ActivityLogDeleteTest extends TestCase
  {
      use DatabaseTransactions;

      private User $admin;
      private User $encoder;

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

          $this->encoder = User::firstOrCreate(
              ['email' => 'encoder@osca.local'],
              ['name' => 'OSCA Encoder', 'password' => Hash::make('password')]
          );
          $this->encoder->syncRoles(['encoder']);
      }

      /** Create N activity log entries owned by the admin user. */
      private function createLogs(int $count): \Illuminate\Database\Eloquent\Collection
      {
          $senior = SeniorCitizen::first();   // uses seeded data

          $logs = collect();
          for ($i = 0; $i < $count; $i++) {
              $logs->push(ActivityLog::create([
                  'user_id'      => $this->admin->id,
                  'action'       => 'created',
                  'subject_type' => SeniorCitizen::class,
                  'subject_id'   => $senior?->id ?? 1,
                  'description'  => "Test log entry {$i}",
                  'ip_address'   => '127.0.0.1',
              ]));
          }
          return $logs;
      }

      // ── bulkDestroy ───────────────────────────────────────────────────────

      #[Test]
      public function bulk_destroy_deletes_only_selected_log_entries(): void
      {
          $logs    = $this->createLogs(3);
          $toDelete = $logs->take(2)->pluck('id')->toArray();
          $keep     = $logs->last()->id;

          $this->actingAs($this->admin)
               ->delete(route('activity-log.bulk-destroy'), ['ids' => $toDelete])
               ->assertRedirect();

          foreach ($toDelete as $id) {
              $this->assertDatabaseMissing('activity_logs', ['id' => $id]);
          }
          $this->assertDatabaseHas('activity_logs', ['id' => $keep]);
      }

      #[Test]
      public function bulk_destroy_requires_ids_to_be_present(): void
      {
          $this->actingAs($this->admin)
               ->delete(route('activity-log.bulk-destroy'), ['ids' => []])
               ->assertSessionHasErrors('ids');
      }

      #[Test]
      public function bulk_destroy_is_forbidden_for_encoder(): void
      {
          $logs = $this->createLogs(2);

          $this->actingAs($this->encoder)
               ->delete(route('activity-log.bulk-destroy'), ['ids' => $logs->pluck('id')->toArray()])
               ->assertForbidden();

          // Entries must still exist.
          foreach ($logs as $log) {
              $this->assertDatabaseHas('activity_logs', ['id' => $log->id]);
          }
      }

      #[Test]
      public function bulk_destroy_requires_authentication(): void
      {
          $logs = $this->createLogs(2);
          $this->delete(route('activity-log.bulk-destroy'), ['ids' => $logs->pluck('id')->toArray()])
               ->assertRedirect(route('login'));
      }

      // ── clear ─────────────────────────────────────────────────────────────

      #[Test]
      public function clear_deletes_all_log_entries_created_in_test(): void
      {
          // Count rows that exist BEFORE this test creates anything.
          $baseline = ActivityLog::count();
          $this->createLogs(4);
          $this->assertEquals($baseline + 4, ActivityLog::count());

          $this->actingAs($this->admin)
               ->delete(route('activity-log.clear'))
               ->assertRedirect(route('activity-log.index'));

          $this->assertEquals(0, ActivityLog::count());
      }

      #[Test]
      public function clear_is_forbidden_for_encoder(): void
      {
          $this->createLogs(2);
          $countBefore = ActivityLog::count();

          $this->actingAs($this->encoder)
               ->delete(route('activity-log.clear'))
               ->assertForbidden();

          $this->assertEquals($countBefore, ActivityLog::count());
      }

      #[Test]
      public function clear_requires_authentication(): void
      {
          $this->delete(route('activity-log.clear'))
               ->assertRedirect(route('login'));
      }
  }
  ```

- [ ] **Step 4.2 — Run to confirm all tests fail**

  ```
  php artisan test --filter=ActivityLogDeleteTest
  ```

  Expected: all 7 tests **FAIL** — routes do not exist yet.

- [ ] **Step 4.3 — Refactor the activity-log route in `routes/web.php`**

  Find and replace the existing single route:

  ```php
  // Before:
  Route::get('/activity-log', [ActivityLogController::class, 'index'])
      ->name('activity-log.index')
      ->middleware('role:admin');
  ```

  ```php
  // After:
  Route::middleware('role:admin')->prefix('activity-log')->name('activity-log.')->group(function () {
      Route::get('/',       [\App\Http\Controllers\ActivityLogController::class, 'index'])->name('index');
      Route::delete('bulk', [\App\Http\Controllers\ActivityLogController::class, 'bulkDestroy'])->name('bulk-destroy');
      Route::delete('all',  [\App\Http\Controllers\ActivityLogController::class, 'clear'])->name('clear');
  });
  ```

  Check whether `ActivityLogController` is already imported at the top of `web.php`. If there is no `use` statement for it, the fully-qualified class names above (`\App\Http\Controllers\...`) are sufficient. If there is a `use` statement, use the short name:

  ```php
  Route::get('/',       [ActivityLogController::class, 'index'])->name('index');
  Route::delete('bulk', [ActivityLogController::class, 'bulkDestroy'])->name('bulk-destroy');
  Route::delete('all',  [ActivityLogController::class, 'clear'])->name('clear');
  ```

- [ ] **Step 4.4 — Add `bulkDestroy()` and `clear()` to the controller**

  Open `app/Http/Controllers/ActivityLogController.php`. Add these two methods after `index()`:

  ```php
  public function bulkDestroy(Request $request)
  {
      $request->validate([
          'ids'   => ['required', 'array'],
          'ids.*' => ['integer'],
      ]);

      ActivityLog::whereIn('id', $request->ids)->delete();

      $count = count($request->ids);
      $noun  = $count === 1 ? 'entry' : 'entries';
      return back()->with('success', "{$count} log {$noun} deleted.");
  }

  public function clear()
  {
      // Use delete() rather than truncate() — truncate issues an implicit
      // commit in MySQL which breaks DatabaseTransactions in tests.
      ActivityLog::query()->delete();

      return redirect()->route('activity-log.index')
          ->with('success', 'All activity log entries have been cleared.');
  }
  ```

  The `Request` import is already at the top of the file. `ActivityLog` is already used in `index()`.

- [ ] **Step 4.5 — Run to confirm all 7 tests pass**

  ```
  php artisan test --filter=ActivityLogDeleteTest
  ```

  Expected: all 7 **PASS**.

- [ ] **Step 4.6 — Run the full test suite to confirm no regressions**

  ```
  php artisan test
  ```

  Expected: all existing tests still **PASS**.

- [ ] **Step 4.7 — Commit**

  ```
  git add routes/web.php app/Http/Controllers/ActivityLogController.php tests/Feature/ActivityLogDeleteTest.php
  git commit -m "feat: add bulk delete and clear all to activity log

  Admin-only DELETE routes:
    DELETE /activity-log/bulk  -> bulkDestroy(ids[])
    DELETE /activity-log/all   -> clear() (deletes all rows)

  Uses query()->delete() (not truncate) so DatabaseTransactions
  test isolation is preserved."
  ```

---

## Task 5: Activity log delete — view

**Files:**
- Modify: `resources/views/activity_log/index.blade.php`

---

- [ ] **Step 5.1 — Add the "Clear All" button to the filter card header**

  Open `resources/views/activity_log/index.blade.php`. Find this line in the filter card header:

  ```blade
  <span class="text-[12px] text-ink-400">{{ number_format($logs->total()) }} entries</span>
  ```

  Replace it with:

  ```blade
  <div class="flex items-center gap-3">
      <span class="text-[12px] text-ink-400">{{ number_format($logs->total()) }} entries</span>
      <form method="POST" action="{{ route('activity-log.clear') }}" x-data
            @submit.prevent="if (confirm('Permanently delete ALL {{ number_format($logs->total()) }} log entries? This cannot be undone.')) $el.submit()">
          @csrf @method('DELETE')
          <button type="submit" class="btn btn-ghost text-xs text-critical-700 hover:bg-critical-50">
              Clear All
          </button>
      </form>
  </div>
  ```

- [ ] **Step 5.2 — Add Alpine bulk-select state and wrap the table card**

  Find the outer `<div class="card overflow-hidden">` that contains the log table. Wrap it and everything through to its closing `</div>` in an Alpine component div:

  ```blade
  <div x-data="{
      selected: [],
      get allIds() { return [...$el.querySelectorAll('.row-cb')].map(c => parseInt(c.value)); },
      toggleAll(checked) { this.selected = checked ? this.allIds : []; },
      toggle(id) {
          this.selected.includes(id)
              ? this.selected = this.selected.filter(i => i !== id)
              : this.selected.push(id);
      }
  }">

      {{-- existing table card div starts here --}}
      <div class="card overflow-hidden">
          ...
      </div>
      {{-- end existing table card --}}

  </div>{{-- end Alpine wrapper --}}
  ```

- [ ] **Step 5.3 — Add checkbox column to `<thead>`**

  Find the `<thead><tr>` row. Add a checkbox `<th>` as the **first** column, before the existing "When" column:

  ```blade
  <thead>
      <tr>
          <th class="th w-8 pr-0">
              <input type="checkbox"
                     @change="toggleAll($event.target.checked)"
                     :checked="allIds.length > 0 && selected.length === allIds.length"
                     class="rounded border-paper-rule text-forest-700 focus:ring-forest-500">
          </th>
          <th class="th">When</th>
          <th class="th">User</th>
          <th class="th">Action</th>
          <th class="th">Description</th>
          <th class="th">IP</th>
      </tr>
  </thead>
  ```

- [ ] **Step 5.4 — Add checkbox cell to each data row**

  Inside the `@forelse ($logs as $log)` loop, add a checkbox `<td>` as the **first** cell of each `<tr>`, before the "When" cell:

  ```blade
  <tr class="hover:bg-forest-50/40 dark:hover:bg-forest-900/10 transition-colors">
      <td class="td w-8 pr-0">
          <input type="checkbox" class="row-cb rounded border-paper-rule text-forest-700 focus:ring-forest-500"
                 value="{{ $log->id }}"
                 :checked="selected.includes({{ $log->id }})"
                 @change="toggle({{ $log->id }})">
      </td>
      {{-- existing When / User / Action / Description / IP cells unchanged --}}
  ```

- [ ] **Step 5.5 — Fix the empty-state colspan**

  Find the empty-state `<tr>`:

  ```blade
  <td colspan="5" class="px-4 py-16 text-center text-ink-400">
  ```

  Change `colspan="5"` to `colspan="6"` (the checkbox column added one more).

- [ ] **Step 5.6 — Add the floating action bar inside the Alpine wrapper**

  Place this block immediately **after** the closing `</div>` of the table card div (but still inside the Alpine wrapper div):

  ```blade
  {{-- Floating action bar — appears when at least one row is checked --}}
  <div x-show="selected.length > 0" x-cloak x-transition
       class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50
              bg-ink-900 text-white rounded-xl shadow-xl
              flex items-center gap-4 px-5 py-3 text-sm">

      <span x-text="`${selected.length} selected`" class="font-medium tabular-nums"></span>

      <form method="POST" action="{{ route('activity-log.bulk-destroy') }}">
          @csrf @method('DELETE')
          <template x-for="id in selected" :key="id">
              <input type="hidden" name="ids[]" :value="id">
          </template>
          <button type="submit"
                  @click.prevent="
                      const noun = selected.length === 1 ? 'entry' : 'entries';
                      if (confirm(\`Permanently delete \${selected.length} log \${noun}? This cannot be undone.\`))
                          \$el.closest('form').submit()
                  "
                  class="btn bg-critical-600 text-white hover:bg-critical-700 border-transparent text-xs py-1.5">
              Delete Selected
          </button>
      </form>

      <button @click="selected = []"
              class="text-white/50 hover:text-white text-xs underline underline-offset-2">
          Deselect all
      </button>
  </div>
  ```

- [ ] **Step 5.7 — Smoke-test in the browser**

  Navigate to `http://127.0.0.1:8000/activity-log` (logged in as admin).

  Verify:
  - "Clear All" button appears in the filter card header next to the entry count
  - Each row has a checkbox in the first column
  - "Select All" checkbox in the header checks/unchecks all rows
  - Selecting any rows reveals the floating dark action bar at the bottom with "X selected" and "Delete Selected"
  - "Deselect all" clears the selection and hides the bar
  - Clicking "Delete Selected" shows a confirmation dialog; confirming removes the rows and shows a flash message
  - Clicking "Clear All" shows a confirmation dialog; confirming wipes the table and redirects back with a flash message
  - As an encoder (log out, log in as encoder@osca.local) — the "Clear All" button should not be visible (or the route returns 403 if hit directly)

  > **Note:** The "Clear All" button renders for any authenticated user who can reach the page. Since the page itself is `middleware('role:admin')`, only admins reach it. No additional role check is needed in the view.

- [ ] **Step 5.8 — Commit**

  ```
  git add resources/views/activity_log/index.blade.php
  git commit -m "feat: activity log bulk delete UI

  - Clear All button in filter card header (with confirmation)
  - Checkbox column per row with Select All in thead
  - Floating action bar: 'X selected' + Delete Selected + Deselect all
  - Empty-state colspan updated from 5 to 6"
  ```

---

## Self-Review

**Spec coverage:**

| Spec requirement | Task |
|---|---|
| Fix age = 0 in all exports | Task 1 (accessor fix + test) |
| Batch "last run" on batch page | Tasks 2–3 (controller + view) |
| Activity log Clear All | Task 4 (routes/controller) + Task 5 (view) |
| Activity log bulk select + delete | Task 4 (routes/controller) + Task 5 (view) |
| Admin-only for delete operations | Task 4 (`role:admin` group middleware) |
| No migrations required | ✅ none in any task |

**Placeholder scan:** No TBDs, no "similar to above", no "add validation" — all validation code is shown inline. ✅

**Type consistency:**
- Route name `activity-log.bulk-destroy` used in controller test (`route('activity-log.bulk-destroy')`) and in the view (`route('activity-log.bulk-destroy')`). Matches the route definition `->name('bulk-destroy')` inside the `name('activity-log.')` group. ✅
- Route name `activity-log.clear` matches definition. ✅
- Cache key `ml_last_batch_started` written in Task 2 Step 2.3 and read in the test Step 2.1 and in the view Step 3.1. ✅
- Cache key `ml_last_batch_senior_count` consistent throughout. ✅
- `$lastBatchRun` / `$lastBatchCount` view variable names match `->with()` calls and Blade references. ✅
