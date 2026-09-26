# Booking index profiling

## Dataset and request

The representative dataset is created by `ProfilingDatasetSeeder`:

- 250 customers
- 50 resources
- 5,000 future slots
- 3,000 existing bookings
- 1,500 booking documents

The measured request is the authenticated index page:

```bash
GET /api/booking?per_page=50
```

For proof, the branch also exposes a local/testing-only bottleneck variant:

```bash
GET /api/booking?profile_bottleneck=1&per_page=50
```

Use that variant in Telescope to inspect the N+1 baseline, then retest the default endpoint.

## Reproduce

```bash
php artisan migrate:fresh --force
php artisan db:seed --class=ProfilingDatasetSeeder --force
php artisan profile:booking-endpoints --iterations=10 --output=docs/profiling/booking-index-results.json
```

The command records total latency, p50/p95 latency, query count, query time, memory, cache events, queued jobs, Laravel HTTP-client calls, statuses, and duplicated SQL fingerprints. Raw output is stored in `docs/profiling/booking-index-results.json`.

## Baseline Evidence

Baseline endpoint: `GET /api/booking?profile_bottleneck=1&per_page=50`

| Metric | Result |
|---|---:|
| Requests | 10 |
| Status | 200 |
| Total time | 5,075.23 ms |
| p50 / p95 | 484.10 ms / 625.14 ms |
| Queries | 6,053 total, 605.3 per request |
| Query time | 3,086.14 ms total, 308.61 ms per request |
| Memory | 14 MB peak |
| Cache calls | 0 hits, 0 misses |
| External calls | 0 |

Duplicated work was the dominant cost. The baseline called relationship query methods inside the response loop even though the page size was only 50:

| Duplicated SQL fingerprint | Count |
|---|---:|
| `select * from slots where slots.id = ? limit ?` | 2,000 |
| `select * from customers where customers.id = ? limit ?` | 1,501 |
| `select * from resources where resources.id = ? limit ?` | 1,500 |
| `select count(*) ... from booking_documents where booking_id = ?` | 500 |
| `select exists(...) from booking_documents where booking_id = ?` | 500 |

## Change

The highest-impact change was to remove the per-row relationship reads from the index response:

- `BookingRepository::indexPage()` uses `withoutEagerLoads()` so the model-level default eager loads do not pull more than this endpoint needs.
- It explicitly eager loads `customer`, `resource`, and `slot` with selected columns.
- It uses `withCount('documents')` instead of per-booking `documents()->count()` and `documents()->exists()` calls.
- The controller maps the already-loaded relationships instead of calling relation query builders.

## Verified Result

Optimized endpoint: `GET /api/booking?per_page=50`

| Metric | Baseline | Optimized |
|---|---:|---:|
| Total time | 5,075.23 ms | 173.14 ms |
| p95 latency | 625.14 ms | 26.45 ms |
| Queries/request | 605.3 | 5.0 |
| Query time/request | 308.61 ms | 4.34 ms |
| Duplicated SQL fingerprints | 5 | 0 |
| Cache calls | 0 | 0 |
| External calls | 0 | 0 |
| Memory peak | 14 MB | 14 MB |

This reduced query count by 99.2% and p95 latency by 95.8% for the measured request. The remaining queries are the fixed-cost page/auth/eager-load work for the request; there are no per-row relationship queries left.

## Tool Overhead

The JSON measurement is taken by an in-process Artisan command with Telescope and Debugbar out of the request path. That separates application cost from development-tool overhead. Telescope is still useful for request-by-request inspection of the bottleneck variant, but its own storage writes, watchers, serialization, and UI queries should not be mixed into the final application-cost number.

Debugbar has the same problem: it collects and renders diagnostic data on each request. That changes latency and memory, and it can make an endpoint look slower than it is. Use it to inspect locally, then verify with the repeatable command.

## Production Restrictions

Telescope and Debugbar must be restricted in production because they can expose request bodies, headers, tokens, SQL, model data, exceptions, jobs, cache keys, and timing information. They also add database writes and memory/CPU work to normal traffic. Telescope in this app is opt-in through `TELESCOPE_ENABLED`, protected by an authenticated allow-list, scoped away from its own UI path, and configured to redact sensitive request data. Keep it enabled only for short profiling windows, prefer an isolated profiling database, and prune its data afterwards.
