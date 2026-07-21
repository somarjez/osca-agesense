# Scaling to 7,000–10,000 Seniors

> **Audience:** Whoever deploys or maintains this system as the senior count grows well past
> its original ~365-record scale. Written 2026-07-21 after measuring the system at a real
> 10,000-record dataset (see `app/Console/Commands/SeedLoadTest.php`).

## Summary

The app's data layer (SQL aggregates, caching, pagination, indexes) and its ML pipeline
(async/queued, O(1) per senior) were already built to scale. Two real problems were found and
fixed by measuring at 10,000 records rather than guessing:

1. **GIS map (fixed) — was a hard crash, not just slowness.** `GisApiController::seniors()`
   used to ship one GeoJSON feature per senior. At 10k records that's an ~11 MB response and
   ~196 MB peak PHP memory — a **fatal, uncaught PHP memory-limit error** under the very common
   `memory_limit = 128M` default (shared hosting, a default `php.ini`, etc.), confirmed by
   calling the controller directly under both the default and a raised `memory_limit` (see git
   history for the now-removed `diag:gis-memory` command used to reproduce this). The fix: the
   map now requests a small SQL-only, barangay-level aggregate (bubbles) by default, and fetches
   full per-senior detail only when a specific barangay is picked — bounded to a few hundred
   rows, never the whole table. See `GisApiController::buildAggregatedPayload()` and
   `resources/views/reports/gis.blade.php` (`renderAggregateBubbles`,
   `upgradeToFullSeniorDetail`).
   **Known limitation:** this fix is in the *client's request pattern* — the map page always
   requests `?aggregate=1` first. `GET /api/gis/seniors` called directly with **no** query
   params still returns the old full per-senior payload and will still hit the memory ceiling
   at 10k+. This is intentional, not an oversight: `app/Console/Commands/CacheGisRouteDistances.php`
   and the `GisPrecisionByRoleTest`/`GisApiCachingTest` suites call the bare endpoint and depend
   on it returning full per-senior data by default. If a future integration (a mobile app,
   another internal tool, an external report) needs to call this API directly at 10k+ scale, it
   must pass `?aggregate=1` (or a `?barangay=` filter) itself — the endpoint does not protect
   callers who don't opt in.
2. **Report pages (fixed) — were genuinely slow, not crashing.** Several controllers rebuilt
   the same "latest ML result per active senior" list on every request by pulling every active
   senior's id into PHP (`SeniorCitizen::active()->pluck('id')`) and binding it into a giant
   `WHERE ... IN (...)` clause. Measured at 10k records: 3.6–5.7s per report page. Replacing
   this with the existing cached `ClusterAnalyticsService::latestResultIds()` (a `whereHas`
   EXISTS query, 5-minute cache) cut that to 0.6–2s. See `ReportController`,
   `SeniorCitizenController::index()`.
3. **Bulk import (fixed) — was ~3-4 queries per row in one long transaction.** A 10k-row
   import meant ~30k-40k queries held in a single open transaction. Fixed by batch-prefetching
   duplicate/osca-id checks once instead of per row, and committing every 500 rows instead of
   one all-or-nothing transaction. Verified against a synthetic 1,200-row file crossing chunk
   boundaries (`BulkUploadController::upload()`).

None of this required schema changes or new infrastructure — it's query-shape and payload-shape
fixes. The one thing that **is** an infra decision is queue throughput, covered below.

## Reproducing the measurements

```powershell
# Seed ~10k synthetic seniors (bulk-inserted directly, tagged encoded_by=LOAD_TEST_SEED —
# does not touch real data, does not run the Python ML pipeline).
php artisan osca:seed-loadtest --count=9635 --force

# ... exercise pages, profile, etc. ...

# Remove the synthetic rows when done.
php artisan osca:seed-loadtest --fresh --count=0 --force
```

The seeder inserts `senior_citizens` + `qol_surveys` + `ml_results` (+ a few `recommendations`
per senior) directly via chunked `DB::table()->insert()`, bypassing Eloquent events and the
Python ML pipeline — realistic enough for query/payload-size profiling without running real
inference 10,000 times. **Never run it against a production database.**

## Queue throughput: local vs. online deployment

The ML pipeline is already fully async — `RunMlPipeline` (survey submit) and `ProcessMlSingle`
(re-analyze) are dispatched jobs, and batch scoring (`ProcessMlBatch`) chunks 100 seniors per
job via `Bus::batch()`. This does **not** need to change to reach 10k. What changes is how much
queue *throughput* you have, which is a deployment-time config choice, not a code change:

| | Local / plain hosting (default) | Online / VPS with more control |
|---|---|---|
| `QUEUE_CONNECTION` | `database` (default, `config/queue.php:16`) | `redis` — already configured in `config/queue.php`, just switch the env var + install `predis/predis` or the phpredis extension |
| Workers | One `php artisan queue:work` is fine | Run several `queue:work --queue=ml` processes (e.g. via `supervisor` or the OS's process manager) to score imports/re-analyses faster in parallel |
| `DB_QUEUE_RETRY_AFTER` | Already set to 600s — must stay above `ProcessMlBatch`/`ProcessMlSingle`'s 300s `$timeout` (see the comment at `config/queue.php:42-46`) or the database driver will re-dispatch a still-running chunk as "stalled" | Same constraint applies under Redis |

**Nothing here is required to run locally** — the `database` queue driver with one worker is
the working default and needs no extra services. Redis is a throughput upgrade, not a
correctness requirement.

### One tuning note carried over from measurement, not yet re-verified against real inference

`ProcessMlBatch` chunks are 100 seniors, `timeout=300`. The local-Python subprocess path sizes
its own timeout as roughly `max(timeout, coldStart) + count * 2` seconds
(`MlService::callBatchLocal()`), so a 100-senior chunk is already close to that 300s ceiling
before accounting for cold-start. This wasn't re-measured with real inference in this pass
(the load-test seeder bypasses Python on purpose, per above). If a 10k-record batch run in
practice shows the queue re-dispatching chunks as "stalled" or workers timing out, lower
`--chunk` on `ml:batch-analyze` / the `ProcessMlBatch` chunk size in `MlController::batchRun()`
and `BulkUploadController::upload()` (currently 100) rather than raising `retry_after` further.

## What was *not* changed

- The already-optimized GIS client-side rendering (raster masking, canvas markers, marker
  clustering) — see the `gis-module` skill and
  `docs/superpowers/specs/2026-06-04-gis-map-performance-design.md`. This pass only changed
  *how much data* reaches the browser, not how it's drawn once there.
- Database schema / indexes — the existing index set (see migrations under
  `2026_05_13_200000_add_indexes_to_ml_results.php`,
  `2026_06_28_000001_add_dashboard_aggregate_indexes.php`,
  `2026_07_20_000001_add_status_barangay_index_to_senior_citizens.php`, etc.) already covers
  the hot filter/join columns.
