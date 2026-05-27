# Design: Export Age Fix · Batch Last-Run Timestamp · Activity Log Delete
**Date:** 2026-05-25
**Status:** Approved
**Scope:** Three independent improvements — one bug fix, one feature addition, one feature addition.

---

## 1. Age = 0 in All Exports (Bug Fix)

### Problem
All CSV and Excel exports show `0` for every senior's age column.

### Root Cause
`SeniorCitizen::getAgeAttribute()` computes age from `$this->date_of_birth`:

```php
public function getAgeAttribute(): int
{
    return $this->date_of_birth?->diffInYears(now()) ?? 0;
}
```

The cluster and risk CSV export queries select `TIMESTAMPDIFF(...) as age` via `DB::raw(DbHelper::ageExpr(...))` but do **not** include `senior_citizens.date_of_birth` in the `SELECT` clause. When Eloquent hydrates the result, `$this->date_of_birth` is `null` — the accessor returns `?? 0`. The correct SQL-computed value is present in `$this->attributes['age']` but is never read.

The registry (Excel) export does select `date_of_birth`, so the accessor computes correctly there — but it bypasses the already-computed SQL value unnecessarily.

The PDF export loads the full model via route model binding, so it is unaffected.

### Fix

**File:** `app/Models/SeniorCitizen.php`

```php
public function getAgeAttribute(): int
{
    // When a query has pre-computed age via SQL (all export queries),
    // use that value directly rather than recomputing from date_of_birth.
    if (array_key_exists('age', $this->attributes)) {
        return (int) $this->attributes['age'];
    }
    // Fallback for full model loads (profile page, PDF, show view).
    return $this->date_of_birth?->diffInYears(now()) ?? 0;
}
```

**No other file changes required.** No query changes. No view changes.

### Affected exports
| Export | Before | After |
|---|---|---|
| `exportCluster()` → CSV | 0 | Correct age from SQL |
| `exportRisk()` → CSV | 0 | Correct age from SQL |
| `exportRegistry()` → Excel | Correct (via Carbon) | Correct (via SQL, faster) |
| PDF individual profile | Correct (full model) | Unchanged |

---

## 2. Batch Analysis "Last Run" Timestamp (Feature)

### Problem
The `/ml/batch` page gives no indication of when the last full batch analysis was run. Staff cannot tell if results are fresh or weeks old without checking individual ML result timestamps.

### Design

#### Controller — `app/Http/Controllers/MlController.php`

**`batchRun()`** — add two cache writes immediately after `Bus::batch(...)->dispatch()`:

```php
Cache::put('ml_last_batch_started',      now(),              now()->addDays(90));
Cache::put('ml_last_batch_senior_count', count($seniorIds),  now()->addDays(90));
```

- **90-day TTL**: survives normal development/pilot use; resets on explicit `cache:clear` (acceptable).
- `CACHE_STORE=database` (the project default) means the values survive server restarts.

**`batchIndex()`** — pass both values to the view:

```php
return view('ml.batch', compact('pending', 'totalEligible'))
    ->with('lastBatchRun',   Cache::get('ml_last_batch_started'))
    ->with('lastBatchCount', Cache::get('ml_last_batch_senior_count'));
```

#### View — `resources/views/ml/batch.blade.php`

Add one metadata line directly below the existing subtitle paragraph inside the "Run Full Batch Assessment" card:

```blade
<p class="text-xs text-ink-400 mt-1">
    Last run:
    @if ($lastBatchRun)
        {{ $lastBatchRun->format('d M Y, g:i A') }}
        &middot; {{ $lastBatchCount }} senior(s)
    @else
        <span class="text-ink-300">Never run on this machine</span>
    @endif
</p>
```

**"Never run on this machine" caveat:** The cache is device-local. If a colleague's machine has never dispatched a batch it will show this label even if another device has run it. The label makes the scope explicit.

### Files changed
- `app/Http/Controllers/MlController.php` — `batchRun()` (+2 lines), `batchIndex()` (+2 lines)
- `resources/views/ml/batch.blade.php` — metadata paragraph (+7 lines)

---

## 3. Activity Log Bulk Delete + Clear All (Feature)

### Problem
The Activity Log page has no way to remove entries. The controller only exposes `index()`. For data hygiene and privacy compliance, admin users need to delete individual entries in bulk and clear the entire log.

### Design

#### Routes — `routes/web.php`

Replace the existing single route:
```php
// Before:
Route::get('/activity-log', [ActivityLogController::class, 'index'])
    ->name('activity-log.index')
    ->middleware('role:admin');
```

With a grouped block:
```php
// After:
Route::middleware('role:admin')->prefix('activity-log')->name('activity-log.')->group(function () {
    Route::get('/',       [ActivityLogController::class, 'index'])->name('index');
    Route::delete('bulk', [ActivityLogController::class, 'bulkDestroy'])->name('bulk-destroy');
    Route::delete('all',  [ActivityLogController::class, 'clear'])->name('clear');
});
```

Both new routes are admin-only (inherited from the group middleware).

#### Controller — `app/Http/Controllers/ActivityLogController.php`

Two new methods added after `index()`:

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
    ActivityLog::truncate();
    return redirect()->route('activity-log.index')
        ->with('success', 'All activity log entries have been cleared.');
}
```

- `truncate()` is used in `clear()` for performance on large tables; it also resets the auto-increment counter. A `redirect()` (not `back()`) is used because returning to a paginated page after truncation causes a confusing empty state at the current page offset.
- `bulkDestroy()` uses `delete()` (not `truncate()`) so soft-delete observers still fire if the model ever gains `SoftDeletes`.

#### View — `resources/views/activity_log/index.blade.php`

Three additions:

**A. "Clear All" button in the filter card header**

Replace the existing header span:
```blade
{{-- Before --}}
<span class="text-[12px] text-ink-400">{{ number_format($logs->total()) }} entries</span>

{{-- After --}}
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

**B. Checkbox column + Alpine bulk-select state**

Wrap the card's table section in an Alpine component:
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
```

Add a checkbox `<th>` as the first column in `<thead>`:
```blade
<th class="th w-8 pr-0">
    <input type="checkbox"
           @change="toggleAll($event.target.checked)"
           :checked="allIds.length > 0 && selected.length === allIds.length"
           class="rounded border-paper-rule text-forest-700 focus:ring-forest-500">
</th>
```

Add a checkbox `<td>` as the first cell in each data row:
```blade
<td class="td w-8 pr-0">
    <input type="checkbox" class="row-cb rounded border-paper-rule text-forest-700 focus:ring-forest-500"
           value="{{ $log->id }}"
           :checked="selected.includes({{ $log->id }})"
           @change="toggle({{ $log->id }})">
</td>
```

Update the `colspan` on the "No activity" empty-state row from `5` to `6`.

**C. Floating action bar**

Placed just before the closing `</div>` of the Alpine wrapper, after the table card:
```blade
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
                @click.prevent="if (confirm(`Permanently delete ${selected.length} log ${selected.length === 1 ? 'entry' : 'entries'}? This cannot be undone.`)) $el.closest('form').submit()"
                class="btn bg-critical-600 text-white hover:bg-critical-700 border-transparent text-xs py-1.5">
            Delete Selected
        </button>
    </form>

    <button @click="selected = []"
            class="text-white/50 hover:text-white text-xs underline underline-offset-2">
        Deselect all
    </button>
</div>

</div>{{-- end Alpine wrapper --}}
```

### Files changed
- `routes/web.php` — route group refactor (+2 routes)
- `app/Http/Controllers/ActivityLogController.php` — 2 new methods
- `resources/views/activity_log/index.blade.php` — Clear All button, checkbox column, floating action bar

---

## Summary of all file changes

| File | Type | Change |
|---|---|---|
| `app/Models/SeniorCitizen.php` | Bug fix | `getAgeAttribute()` checks `$this->attributes['age']` first |
| `app/Http/Controllers/MlController.php` | Feature | 2 cache writes in `batchRun()`, 2 `->with()` in `batchIndex()` |
| `resources/views/ml/batch.blade.php` | Feature | Last-run metadata line in the run card |
| `routes/web.php` | Feature | Activity-log route group + 2 DELETE routes |
| `app/Http/Controllers/ActivityLogController.php` | Feature | `bulkDestroy()` and `clear()` methods |
| `resources/views/activity_log/index.blade.php` | Feature | Clear All button, checkbox column, floating action bar |

**No migrations. No new models. No JS build changes.**
