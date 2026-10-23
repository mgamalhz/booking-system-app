# Database constraint audit

## Changed constraints

| Constraint | Business reason | Existing data / deletion |
| --- | --- | --- |
| `bookings_enforced_one_to_one_slot_unique` on generated `enforced_one_to_one_slot_id` | At most one non-deleted pending/confirmed one-on-one reservation per slot, matching the existing slot-wide rule. Raw inserts, bulk updates, restores and type changes must obey it without model hooks. | The database derives the guard for every existing row; stale/missing application guards cannot affect it. Canceled/completed/deleted/group/recurring bookings derive NULL and can coexist. Deletion releases the slot. |
| Remove `bookings_active_one_to_one_slot_unique` | Its writable guard could be omitted to bypass uniqueness or become stale after soft deletion and block a valid booking. | Keep the legacy column and model hook for compatibility with running workers; they no longer enforce the invariant. Install the new unique index before removing the old one. |
| `sessions_user_id_foreign`, `sessions.user_id â†’ users.id`, ON DELETE SET NULL | An authenticated session identity must reference an existing customer (the configured web auth provider). Anonymous sessions are valid. | Null orphan identity references before adding the FK; preserve session payloads. Deleting customers clears identity rather than deleting sessions. Keep the existing user_id index. |

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

1. Take a backup and rehearse both migrations on a production-shaped copy using the deployment's database engine/version. The automated suite uses SQLite; MySQL has not been executed locally (no running server).
2. Pause booking/session writers, including queue workers, during the migration and rollback. MySQL DDL is not transactional; index creation and SQLite table rebuilds can lock tables. Schedule the window based on rehearsal timings. The session migration checks that the configured authentication provider is Customer before changing data; deployments overriding AUTH_MODEL must review the target first.
3. Check conflicting reservations before deployment:

   ```sql
   SELECT slot_id, COUNT(*) AS reservations
   FROM bookings
   WHERE type = 'one-on-one' AND status IN ('pending', 'confirmed') AND deleted_at IS NULL
   GROUP BY slot_id HAVING COUNT(*) > 1;
   ```

   The migration fails before changing the schema if any exist. Resolve actual reservation conflicts with the booking owner before retrying; it must not silently cancel or delete paid reservations. Missing/stale guard values are repaired automatically by derivation. The migration can resume after partially committed MySQL column/index creation.
4. Session orphan references are safely nulled before FK installation. Valid and anonymous identities remain intact. No production database was accessed for this audit.
5. Rollback normalizes legacy booking guards before restoring their unique index, then removes the generated guard. Session rollback drops only the FK; repaired orphan identities remain NULL. Keep writers paused throughout rollback too.

## Reproduction and self-review

Run `php artisan test --filter=DatabaseBookingConstraintsTest`. Tests use direct database writes to prove duplicate inserts and updates fail, lifecycle changes release slots, group/different-slot bookings remain allowed, orphan booking/session rows fail, session deletion uses SET NULL, document ownership cascades, and upgrade/backfill/conflict/rollback/retry behavior works.

Self-review: scope follows existing booking semantics; no speculative uniqueness or financial cascades were introduced. The generated unique index closes model-hook bypasses while retaining worker compatibility. Remaining limits: production-data rehearsal and MySQL runtime validation are required before deployment; general group capacity and overlapping intervals still rely on application behavior.

Validation: full suite passed, 185 tests / 1454 assertions; PHPStan passed; Pint passed for changed PHP files. One existing performance fixture now uses canceled history rather than 1,000 conflicting confirmed reservations for one slot.
