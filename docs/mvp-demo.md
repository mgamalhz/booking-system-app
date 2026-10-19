# MVP demo and handover

The API walkthrough is executable with `php artisan test tests/Feature/MvpJourneyTest.php`. It starts with a fresh migrated database, registers a customer with a real Sanctum token, discovers an active resource and a same-day slot, reserves it, replays the identical idempotent request, and views the owned booking. Checkout uses a fake Paymob client and leaves the booking pending. A signed callback queues capture; running capture twice records one captured payment, one confirmation transition/history entry and one confirmation job. The customer then cancels, sees inventory released, and receives a JSON 409 when attempting to revive the canceled booking.

Additional acceptance checks cover anonymous/cross-owner access, structural/null update rejection, inactive/past inventory, stale model transitions, rollback suppressing confirmation, bad signatures/amounts, retry after capture failure, authoritative group capacity, and atomic recurring reservations over existing inventory. Existing idempotency, creation lock, domain event, reminder, document and profiling tests remain in the full suite.

## Verification

2026-10-19, PHP 8.4.25 with locked Composer dependencies:

- Full suite: 167 tests, 1,430 assertions passed.
- Xdebug/Pest coverage gate: 93.2%, passing the existing 80% minimum.
- Pint and PHPStan level 5: passed; no baseline changes.
- Composer locked audit: no advisories or abandoned packages; npm audit: zero vulnerabilities. Network access was required to run these audits.
- All migrations and the three documented demo seeders passed on a newly created isolated SQLite file. That temporary file was removed; no application database was migrated.
- API route inventory and diff whitespace check passed. The new walkthrough also covers inclusive single-day availability and API time normalization on SQLite.

No production deployment, real charge, refund, external email or S3 operation was performed. The test payment client and fake queue are deliberate demo boundaries, not a claim of live merchant verification.

## Explicitly deferred

- Live MySQL/Redis interprocess contention, merchant authorization/capture/HMAC callbacks, private S3 permissions and mail delivery need staging validation with deployment credentials.
- Automatic refunds, cancellation fees and abandoned/declined checkout reconciliation require merchant policy. Cancellation releases inventory; it does not promise a refund. Processing payments block cancellation and repeated checkout until reconciled.
- Exactly-once effects across gateway/network/worker crashes require provider reconciliation and an outbox/idempotency contract. See README for ambiguous capture outcomes and retry guidance.
- No customer rescheduling/ownership transfer, staff-role API, series-wide payment/cancellation or frontend UI. Trusted internal completion remains available through BookingService; individual recurring bookings can be paid and canceled through the API.
- Inventory provisioning remains operational: use canonical, non-overlapping slot rows. Independent overlapping slot IDs are not treated as the same reservation.

## Review scope

This focused change builds on `final-audit`, which is under review in [PR #37](https://github.com/mgamalhz/booking-system-app/pull/37). Merge that prerequisite before retargeting the MVP PR to main. Existing untracked SQL and OWASP documents are left outside this implementation. `tests/TestCase.php` and its isolated document-disk helper remain unchanged.
