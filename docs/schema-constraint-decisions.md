# Database constraint audit

## Changed constraints

| Constraint | Business reason | Existing data / deletion |
| --- | --- | --- |
| `bookings_enforced_one_to_one_slot_unique` on generated `enforced_one_to_one_slot_id` | At most one non-deleted pending/confirmed one-on-one reservation per slot, matching the existing slot-wide rule. Raw inserts, bulk updates, restores and type changes must obey it without model hooks. | The database derives the guard for every existing row; stale/missing application guards cannot affect it. Canceled/completed/deleted/group/recurring bookings derive NULL and can coexist. Deletion releases the slot. |
| Remove `bookings_active_one_to_one_slot_unique` | Its writable guard could be omitted to bypass uniqueness or become stale after soft deletion and block a valid booking. | Keep the legacy column and model hook for compatibility with running workers; they no longer enforce the invariant. Install the new unique index before removing the old one. |

## Audit of retained rules

| Tables | Decision |
| --- | --- |
| `bookings` | Required customer/slot/resource FKs already exist with CASCADE. Retain established hard-delete ownership semantics; soft deletion preserves history. A retention requirement would justify a separate change to RESTRICT, including status-event FKs. No resource scope added: current availability logic locks/checks the whole slot. |
| `booking_documents` | Existing booking FK CASCADE, booking index and `(disk, key)` uniqueness correctly identify owned metadata and globally identify an object within a storage disk, including trashed metadata. Database cascade does not delete storage objects. |
| `booking_status_events` | Existing booking/customer/slot/resource FKs CASCADE and `(booking_id, occurred_at)` index retained. These events currently share the booking lifecycle; they are not an immutable financial ledger. |
| `idempotency_keys` | Existing `(customer_id, idempotency_key)` unique rule, customer FK CASCADE and created_at cleanup index retained. Different customers may reuse keys. |
| `customers`, `users` | Existing unique emails and nullable unique customer phones retained. Names are not identities. |
| `slots`, `resources` | Existing date/time and availability indexes retained. No evidence that equal slot times or resource names must be globally unique. No overlapping-time exclusion added. |
| `payments`, `paymob_webhook_events` | Unique payment references and webhook transaction IDs already enforce their respective identities. Indexed polymorphic order references cannot have a normal FK; no unsupported one-payment-per-booking rule added. |
| `notifications`, `personal_access_tokens` | Polymorphic owner indexes retained; ordinary FKs cannot reference multiple owner tables. |
| `cancellation_fee_settings` | Multiple active fee bands are intended, so no unique active flag. Overlap policy requires a separate business decision. |
| Cache, locks, jobs, batches, failed jobs, reset tokens | Existing primary/unique keys and expiry/queue indexes retained. Reset-token emails can predate accounts; no identity FK added. |
| Telescope tables | Existing entry UUID uniqueness and cascading tag FK retained; monitoring tags have no required parent. |

## Deployment and existing-data safety

1. Take a backup and rehearse the booking migration on a production-shaped copy using the deployment's database engine/version. The automated suite uses SQLite; MySQL has not been executed locally (no running server).
2. Pause booking writers, including queue workers, during the migration and rollback. MySQL DDL is not transactional; index creation and SQLite table rebuilds can lock tables. Schedule the window based on rehearsal timings.
3. Check conflicting reservations before deployment:

   ```sql
   SELECT slot_id, COUNT(*) AS reservations
   FROM bookings
   WHERE type = 'one-on-one' AND status IN ('pending', 'confirmed') AND deleted_at IS NULL
   GROUP BY slot_id HAVING COUNT(*) > 1;
   ```

   The migration fails before changing the schema if any exist. Resolve actual reservation conflicts with the booking owner before retrying; it must not silently cancel or delete paid reservations. Missing/stale guard values are repaired automatically by derivation. The migration can resume after partially committed MySQL column/index creation.
4. No production database was accessed for this audit. Session identity constraints are outside this change; the application uses Sanctum bearer-token authentication.
5. Rollback normalizes legacy booking guards before restoring their unique index, then removes the generated guard. Keep writers paused throughout rollback too.

## Reproduction and self-review

Run `php artisan test --filter=DatabaseBookingConstraintsTest`. Tests use direct database writes to prove duplicate inserts and updates fail, lifecycle changes release slots, group/different-slot bookings remain allowed, orphan booking rows fail, document ownership cascades, and upgrade/backfill/conflict/rollback/retry behavior works.

Self-review: scope follows existing booking semantics; no speculative uniqueness or financial cascades were introduced. The generated unique index closes model-hook bypasses while retaining worker compatibility. Remaining limits: production-data rehearsal and MySQL runtime validation are required before deployment; general group capacity and overlapping intervals still rely on application behavior.

Validation after removing the session migration: full suite passed, 181 tests / 1447 assertions; Pint passed for the updated test file. One existing performance fixture now uses canceled history rather than 1,000 conflicting confirmed reservations for one slot.
