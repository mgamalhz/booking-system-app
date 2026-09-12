# Slot availability cache

Availability responses are cached in Redis for **120 seconds**. This is short enough to bound the effect of an unexpected missed invalidation while still absorbing repeated calendar reads. The value can be changed with `BOOKING_AVAILABILITY_CACHE_TTL_SECONDS`.

The service uses Laravel's application-wide default cache store (`CACHE_STORE`), which must be configured as `redis` outside tests. It does not maintain a separate availability cache connection.

## Keys and tags

The key contains a schema version and a SHA-256 digest of the resource ID, inclusive date range, requested timezone, normalized filters, configured key version, and the current schedule version. Each entry is tagged `availability:resource:{id}`.

- Creating, cancelling, updating, deleting, or restoring a booking invalidates the old and/or new resource tag after the database transaction commits.
- Creating or changing a slot schedule increments a global schedule version, making every prior schedule key unreachable without an expensive global key scan.
- Change `BOOKING_AVAILABILITY_CACHE_VERSION` when the response shape or availability rules change.

## Correctness and resilience

The booking write path does not consult this cache. It retains its per-slot lock and checks active bookings in the database, so a stale availability response is never the final booking authority.

One request fills a missing key while holding a short distributed lock. Waiters reuse that result. If the lock times out, the request queries the database rather than failing. Any Redis/tag/lock error also bypasses caching and queries the database; invalidation errors are logged and never roll back a successful booking.

Structured `availability_cache.access`, `availability_cache.invalidated`, and warning events expose hits, misses, post-wait hits, stampede fallbacks, latency, and failures to the configured log pipeline.
