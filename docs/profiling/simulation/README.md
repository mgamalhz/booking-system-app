# Three-endpoint performance simulation

**This is an intentionally degraded-code simulation requested for demonstration. It is not a discovery of three production defects.** The final application code is restored to its original optimized implementation. No artificial sleeps, database delay functions, response changes or new indexes were used.

## Results

Each row compares the **same authenticated request, fixture, application clock and response** over 20 measured requests after two warmups. Values are milliseconds. Query counts include the two bearer-authentication reads.

| Real endpoint | p50 before → after | p95 before → after | Queries/request before → after | Mean SQL ms before → after | p50 reduction |
|---|---:|---:|---:|---:|---:|
| `GET /api/bookings?per_page=50` | 94.075 → 14.018 | 126.004 → 17.292 | 204 → 7 | 54.476 → 5.884 | 85.1% |
| `GET /api/bookings/non-paid` | 1,303.661 → 464.043 | 1,703.938 → 495.278 | 2,253 → 6 | 508.398 → 9.618 | 64.4% |
| `GET /api/resources/1/availability?start_date=2030-10-18&end_date=2030-11-17&timezone=Africa%2FCairo` | 207.332 → 82.172 | 241.549 → 87.229 | 314 → 4 | 92.725 → 3.709 | 60.4% |

The exact encoded availability URL is recorded in the JSON files and is authoritative. All 120 before/final measured responses returned HTTP 200. Each endpoint has one stable SHA-256 hash of its decoded JSON response, identical before and after. The index returns 50 bookings (14,863 bytes), non-paid returns 750 bookings (829,535 bytes), and availability returns 200 slots (19,025 bytes). Pagination metadata, ownership, relation fields and timestamps are preserved.

## How the regressions were introduced and fixed

1. **Booking index:** replaced batch eager loading and `withCount('documents')` with three relationship loads plus a document count inside the 50-row loop. This caused 200 per-row SQL executions, plus two pagination and two auth reads. The fix restores selected-column eager loading and the correlated document count in the page query: seven total SQL executions. Indexed document counts still run inside the database; one SQL statement does not imply zero per-row database work.
2. **Non-paid bookings:** disabled model-default eager loading, then loaded the three relationships separately for each of 750 bookings. The fix restores model-default batch eager loading, eliminating 2,250 relationship round trips in favor of three batch reads. Full-list response semantics are retained; no silent pagination or field removal was introduced.
3. **Availability:** fetched the 310 candidate slots first and executed `bookings()->whereIn(...)->exists()` once per slot. The fix pushes occupancy filtering back into the slot SQL using `NOT EXISTS`, retaining active-status and soft-delete predicates. MySQL uses the existing slot/date index and `bookings_slot_status_type_idx`; PHP no longer issues 310 occupancy round trips.

Each fix has its own commit and a new measurement of all three paths. Other defects remain active until their own fix:

| Phase | Index queries | Non-paid queries | Availability queries | Evidence |
|---|---:|---:|---:|---|
| Deliberately degraded baseline | 204 | 2,253 | 314 | [before.json](before.json) |
| Index restored | 7 | 2,253 | 314 | [after-index.json](after-index.json) |
| Non-paid restored | 7 | 6 | 314 | [after-non-paid.json](after-non-paid.json) |
| Availability restored; final | 7 | 6 | 4 | [after.json](after.json) |

## EXPLAIN evidence

[EXPLAIN.md](EXPLAIN.md) summarizes the relevant actual MySQL plans. All raw plans, SQL, sample bindings, execution frequencies and timing samples are in the JSON files under `results.<endpoint>.query_groups` and `samples`. A group represents a normalized query shape in one request; its `executions` count demonstrates the repeated work. SQL and bindings shown for a group are its first actual execution.

The repeated relationship lookups already use primary keys, and occupancy already uses its composite index. The root cause is the number of round trips, so no speculative index migration was added. Main booking-list counts still scan the fixture; the non-paid root query still uses filesort. The report does not claim those plans were improved.

## Conditions and limitations

- PHP 8.4.25, MySQL 8.0.45 and Redis; Telescope disabled. No external gateway/storage calls.
- Separate database `booking_simulation_20261023`; configured application database is left untouched. Existing `ProfilingDatasetSeeder`: 250 customers, 50 resources, 5,000 slots, 3,000 bookings and 1,500 documents. All bookings belong to the profiling customer, 750 pending. Faker seeded once; application clock frozen at `2030-01-01T00:00:00Z`.
- Same existing fixture reused across all four phases; no database rebuild between them. Timing excludes migrations, seeding, cache reset, response transmission and kernel termination. Warm database buffers; serial in-process HTTP-kernel requests, fresh auth guard for each request. This measures application request handling, not external HTTP or concurrency performance.
- Availability cache is forced cold by deleting only the exact fixture key before each request. Date range, resource and timezone are identical in all phases. Token creation and last-used timestamp changes are rolled back after each run.
- p50/p95 are nearest ranks, 10th/19th of 20 samples. Small sample size and workstation noise limit generalization. Mean SQL time and p50 latency are different statistics and should not be directly subtracted to infer CPU time.
- EXPLAIN is run after request measurements and token rollback. Its row counts are optimizer estimates. The token plan therefore reports no matching row after cleanup; it is not evidence about the timed auth lookup. Relevant booking, document and slot data remain present.
- The non-paid response remains relatively costly after N+1 removal because 750 full models and related data are hydrated and serialized. Further pagination would require a separately designed response-compatible change.

## Verification

The three new regression tests were run against the deliberately degraded baseline: all behavior assertions passed, then query budgets failed at **82 vs ≤6**, **61 vs ≤5** and **22 vs ≤3** for 20-row fixtures. After restoration, the full suite passed: **170 tests, 1,628 assertions**. Tests cover owned index ordering/document counts, pending-only ownership/full relationship output, and availability including canceled and soft-deleted reservations. Existing tests cover the broader booking/payment/cache flows.

Also passed: `php vendor/bin/pint --test`, `php vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, and `git diff --check`. No wall-clock thresholds are asserted in tests; query budgets guard the regression without depending on machine speed.

## Replay

The history retains the broken code and all three fixes:

```text
58bd8d3  demo: record deliberately degraded three-endpoint baseline
a9ee977  perf(demo): batch booking index relations and document counts
e8237f5  perf(demo): eager load pending booking relationships in batches
2d70809  perf(demo): replace per-slot occupancy reads with indexed anti-join
```

Use a disposable checkout/worktree of this branch, install Composer dependencies and configure its local `.env` for MySQL and Redis. The MySQL user must be able to create the dedicated database. Run with `APP_ENV=local`; the script never uses `migrate:fresh` or modifies the configured application's dataset. Do not deploy the baseline commit, which intentionally fails the query-budget tests.

At each corresponding commit, run:

```sh
php docs/profiling/simulation/run.php before
php docs/profiling/simulation/run.php after-index
php docs/profiling/simulation/run.php after-non-paid
php docs/profiling/simulation/run.php after
```

Run each command only at its matching code revision. Preserve `before.json` when running later phases: the harness rejects changed paths or JSON response hashes. Each command overwrites its own phase artifact. For a new fixture, provision a fresh dedicated database rather than appending data to a partially seeded one.

## Self-review

Final diff against `main` contains the reproducible harness, evidence and regression tests; application source is unchanged because all deliberate regressions were removed. The commit history is the demonstration. Existing untracked work from the initial investigation is excluded from this PR. The fixtures and cold-cache workload are explicitly synthetic; these measurements cannot be presented as production before/after gains.
