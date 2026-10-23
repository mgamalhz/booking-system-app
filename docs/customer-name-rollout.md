# Customer name: expand, migrate, contract

This PR ships **expand + compatibility + migrate tooling only**. Contract is deliberately
not in the automatic migrations directory. No production database has been modified.

## Shape and compatibility

`customers.name VARCHAR(255)` becomes `given_name VARCHAR(255)` and
`family_name VARCHAR(255)`. Split the trimmed name at the first whitespace run;
the remainder is preserved as one string. A single name has an empty family name.
These are billing fields, not a claim about cultural naming conventions. The original
name and public JSON remain unchanged. Empty/invalid UTF-8 legacy names require operator
correction; errors contain only customer ID and exception class.

During coexistence, `name` is authoritative. Customer model saves write both shapes in
the same INSERT/UPDATE. New billing reads compare the stored parts against a fresh
split of the loaded legacy name and fall back when missing/stale, emitting
`customer_name.legacy_fallback`. Old processes, imports and query-builder updates can
write only `name`: they cannot cause stale billing reads in a subsequently loaded model.
Periodic reconciliation sweeps bring their shadow columns up to date. Direct writes to
the new columns are not supported in this release. Existing display/API readers still
use `name`; this PR is **not contract-ready**.

## Release A: expand before application deploy

1. Back up the database; rehearse on a production-sized clone with the actual engine.
   Check long transactions and metadata-lock queues. Run one migration runner.
2. Run `php artisan migrate --force` with the OLD application still serving traffic.
   The MySQL path explicitly requires `ALGORITHM=INSTANT` and limits metadata-lock
   waiting to five seconds; unsupported engines/versions must fail rather than silently
   copy the table. Retry after removing lock blockers; do not switch to COPY in production.
   SQLite is supported for local validation. Other engines need a separate online-DDL
   review before production use. This PR has not exercised MySQL DDL or concurrent locks.
3. Deploy this application release and restart long-lived queue workers. New code
   requires the expanded schema. Old code safely ignores the nullable additions.
4. Smoke-test registration, login and payment initiation. Observe latency, DB lock waits,
   error rate and fallback logs. Pause rollout if they exceed the existing service SLO.

## Release A: migrate in bounded invocations

```sh
php artisan customers:backfill-names --chunk=100 --max-rows=1000 --after=0
# Persist JSON output, then use its last_id in the next invocation:
php artisan customers:backfill-names --chunk=100 --max-rows=1000 --after=<last_id>
php artisan customers:backfill-names --verify --chunk=100
```

Throttle invocations from the scheduler/operator (e.g. one per minute); run one worker.
Each invocation captures an upper ID and processes at most max-rows; chunk controls ID
fetch size. Resume until `remaining_in_snapshot=0`. New IDs are picked up by subsequent
invocations. Restart at zero for a full reconciliation sweep while old writers exist.
The final pass must start at zero, rather than resuming past rows old writers changed.

Each row uses a short transaction and row lock, reads its current legacy name under
that lock, and updates only mismatched parts, without model events or updated_at changes.
There is no large UPDATE or long transaction. Concurrent app writes serialize on the row;
an old write after the lock is released is handled by fallback and the next sweep.
If interrupted after commit but before output, repeat the last saved cursor safely.
On error, exit 1 and report `failed_id`; `last_id` stays before the failed row. Correct the
source data and resume there. Do not skip errors to manufacture completion.

Verification is a read-only, full scan in bounded memory and is not a consistent snapshot
while writes continue. It outputs total scanned, matches, pending, errors, upper ID and
five redacted ID samples. Exit 1 means pending/errors exist. Save JSON and log extracts
for each run. Repeated verification after old writers are drained is the completion gate.
Inspect additional representative names securely, including Unicode, single names,
leading/trailing whitespace and long remainders; do not export names into PRs/logs.

## Evidence and limitations

Run `php tests/customer-name-rollout-evidence.php` to reproduce the checked-in
[synthetic evidence](evidence/customer-name-backfill.jsonl). The script forces a separate
in-memory SQLite connection and never targets production. 257 rows were processed in
64/64/64/64/1 row invocations with chunk=17. A rerun updated zero rows. Verification
matched 257/257 with zero pending/errors. A simulated old writer produced one pending
row; reconciliation updated exactly one and final verification again matched 257/257.

`php vendor/bin/pest tests/Feature/CustomerNameReshapeTest.php tests/Feature/BookingPaymentTest.php tests/Feature/AuthSanctumTest.php`
passed 13 tests / 94 assertions. Tests also cover error logging, failed cursor retry,
redacted samples, Unicode, stale reads, read-only verification and unchanged API shape.
The full suite (`php vendor/bin/pest --compact`) passed 173 tests / 1,468 assertions.
This is local evidence, not a production completion claim or a MySQL concurrency test.

## Release B: switch every reader/writer (separate PR)

Only after all old web/queue/cron/import versions are drained, deploy a release that
accepts structured names, reads new fields for billing and display, and reconstructs the
public `name` response in application code. Update explicit selects such as
BookingRepository's `customer:id,name,email`, controllers, notifications, payment code,
factories, seeds and integrations. Continue writing legacy name during the rollback
window. Remove all legacy reads, including this PR's fallback/validation comparison,
before considering the old column unused for reads.

Instrument the remaining compatibility writer and external DB access through database
query audit/monitoring. Fallback logs alone cannot prove absence of legacy use: they only
cover billing mismatches. Run new-reader traffic and monitor for at least seven days and
longer than the longest delayed job, scheduled task and agreed rollback window.

## Contract checklist: release C/D, separate approvals and PR

- [ ] Production verification: zero pending/errors on repeated complete scans after drain;
      archive counts, upper IDs, redacted samples, logs and deployment versions.
- [ ] All consumers and explicit projections use structured columns; public API name is
      reconstructed; jobs serialized with the old shape are drained or expired.
- [ ] No old binaries, cron tasks, imports or direct database integrations remain.
- [ ] Audit/query telemetry shows zero legacy reads for the observation window, including
      low-traffic paths. Record dashboard/query links and dates in the contract PR.
- [ ] Release C stops legacy writes. Since `name` is currently NOT NULL, first make it
      nullable using an engine-reviewed online schema change; deploy the writer removal
      with the column still present. Prevent restarting pre-C binaries.
- [ ] Monitor zero legacy reads AND writes for the full observation window after C.
      Recheck null/invalid new fields and structured-name validation separately; legacy
      equivalence verification is no longer valid once legacy writes stop.
- [ ] Backup original names (including exact whitespace), test restore, and sign off the
      shortened rollback boundary. Splitting/trimming is not byte-for-byte reversible.
- [ ] Separate destructive release D drops the two `name` composite indexes
      (`customers_name_email_index`, `customers_name_phone_index`) and then `name`, using
      reviewed online DDL. Review replacement indexes against actual display queries.
      Keep it out of this PR and out of release A's automatic migration batch.

## Rollback

Before contract, stop the backfill and roll application binaries back; the untouched
legacy name remains authoritative. Leave expanded columns in place. Only run expansion
`down()` after every new binary/worker is gone; otherwise writes will fail. Backfilled
data can be discarded and recomputed. A rolling rollback to old code can create stale
parts again; new readers retain fallback until the final switch.

After release C, rollback to an old writer requires restoring mandatory legacy values
from structured fields or backup before old code starts. After D, old binaries cannot
run: restore the old column and indexes, backfill from a secure backup (or explicitly
accept changed formatting), verify, and only then deploy compatible binaries. Prefer
rolling forward. Do not describe contract rollback as a simple migration down.

## Self-review

The old schema/API survive this release, writes are atomic, work is bounded and resumable,
and stale legacy updates remain readable. No destructive migration is shipped. Tradeoffs:
the mixed-version reader still consults legacy name, reconciliation scans all IDs, and
query-builder/import writes are eventually reconciled rather than immediately mirrored.
MySQL online-DDL/concurrency rehearsal and production monitoring are deployment gates.
