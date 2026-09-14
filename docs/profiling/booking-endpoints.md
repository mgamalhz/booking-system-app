# Booking endpoint profiling

## Scope and repeatable workload

The workload uses `ProfilingDatasetSeeder`: 250 customers, 50 resources, 5,000 slots, and 3,000 existing bookings. It runs 10 in-process HTTP requests per endpoint against SQLite with the array cache and sync queue, after a fresh migration and seed. Run it with:

```bash
php artisan migrate:fresh --force
php artisan db:seed --class=ProfilingDatasetSeeder --force
php artisan profile:booking-endpoints --iterations=10
```

The command writes machine-readable output and records p50/p95 latency, query count, database query time, cache hits/misses, queued jobs, Laravel HTTP-client requests, and response statuses. Telescope provides request-by-request inspection when explicitly enabled.

## Telescope safety

Telescope is a development dependency and defaults to disabled in every environment. Enable it only in the profiling environment with `TELESCOPE_ENABLED=true` and set `TELESCOPE_ALLOWED_EMAILS` to a comma-separated operator allow-list. The dashboard always requires an authenticated, allow-listed user, even in `local`. It stores only `api/*`, excludes its own UI, limits request body capture, and redacts passwords, tokens, authorization, cookies, API keys, and CSRF headers. Prefer a separate database through `TELESCOPE_DB_CONNECTION`. Disable it and prune/drop its data after profiling.

## Evidence

Both runs used the workload above on the same machine and dataset shape. All requests returned their expected 200/201 statuses.

| Endpoint | p95 before | p95 after | Queries/request before | Queries/request after | Query ms/request before | Query ms/request after |
|---|---:|---:|---:|---:|---:|---:|
| `POST /api/login` | 13.14 ms | 12.46 ms | 2.0 | 2.0 | 7.14 ms | 8.16 ms |
| `POST /api/booking` | 22.18 ms | 14.00 ms | 9.3 | 9.3 | 4.34 ms | 4.11 ms |
| `POST /api/booking/{id}/update` | 71.92 ms | 44.83 ms | 19.5 | 14.5 | 10.69 ms | 7.29 ms |

The update endpoint was the verified query bottleneck. Route binding had already loaded the booking, but the repository fetched it and its three global eager-load relationships twice more, and the controller fetched everything once again. Updating the bound model and doing one response refresh removed 5 queries per request (25.6%), cut measured query time by 31.8%, and reduced p95 latency by 37.7%.

Cache activity, queued jobs, and external HTTP requests were all zero in both runs. This is expected for these request variants: the cache lock does not emit cache-value hit/miss events, the chosen one-to-one creation remains pending, and none of the endpoints invokes Laravel's HTTP client. Zeros are retained in the JSON output rather than omitted.

Latency on a developer SQLite database is noisy; query count is the primary deterministic signal. The feature test enforces the optimized query ceiling. Raw captured results are in `docs/profiling/results-before.json` and `docs/profiling/results-after.json`.

## Production profiling notes

Repeat the command against an isolated copy of production-shaped data, never the live database because the workload writes bookings and tokens. Keep Telescope enabled only for the short capture window and use `php artisan telescope:prune --hours=1` afterwards. Compare runs with the same database engine, queue/cache configuration, PHP build, machine, and iteration count.
