# Booking System

Authenticated Laravel 13 JSON API for discovering inventory, reserving bookings, Paymob checkout, verified capture, cancellation, documents and reminders. PHP 8.3+ (CI uses 8.4), Composer 2, MySQL 8 and Redis are the normal development stack. Node 24 is needed only for the optional Vite assets.

See [MVP acceptance and verification](docs/mvp-checklist.md), [demo note](docs/mvp-demo.md), [API errors](docs/api-errors.md) and [domain events](docs/booking-status-domain-events.md).

## Local setup

1. Run `composer install`, copy `.env.example` to `.env`, and run `php artisan key:generate`.
2. Create an empty MySQL database named `booking_app`. Set `DB_*` credentials and ensure PHP has `pdo_mysql`, `redis`, `mbstring`, `openssl`, `fileinfo`, DOM/XML and curl enabled. Start MySQL and Redis; configure `REDIS_*` and `CACHE_STORE=redis`.
3. Run `php artisan migrate`. This includes application and Paymob package migrations: customers, resources, slots, bookings, idempotency keys, unique active one-to-one slot reservation, status history, payments, webhook receipts, jobs, failed jobs and cache locks. Do not republish package migrations into an already migrated installation.
4. For unoccupied demo inventory run `php artisan db:seed --class=ResourceSeeder`, `php artisan db:seed --class=SlotSeeder`, and `php artisan db:seed --class=CancellationFeeSettingsSeeder`. These seeders can be rerun; register your own demo customer. The broader `DatabaseSeeder` also creates sample customers and occupied bookings.
5. Start `php artisan serve --host=127.0.0.1 --port=8000`, a worker with `php artisan queue:work --queue=bookings,default --timeout=130 --tries=3 --backoff=10`, and `php artisan schedule:work` in separate terminals. `MAIL_MAILER=log` delivers local emails to `storage/logs/laravel.log`.

The API needs no frontend build. For Vite assets run `npm ci` and `npm run build`; Windows PowerShell users may need `npm.cmd`. `composer setup` also runs migrations and builds assets, so configure MySQL/Redis first. `composer dev` starts the HTTP server, both queue names and Vite after npm dependencies are installed; run the scheduler separately.

For a lightweight single-process demo, use `DB_CONNECTION=sqlite`, create `database/database.sqlite`, set `DB_DATABASE` to its absolute path and `CACHE_STORE=file`. SQLite is supported for setup/tests; availability falls back to database queries when cache tags are unavailable. MySQL plus Redis is required for multi-process contention validation. Never use the array cache for concurrent application workers.

## API walkthrough

Use `Accept: application/json`, `Content-Type: application/json`, and `Authorization: Bearer <token>` after registration/login. All successful application API responses include `success: true`; existing payload names (`booking`, `bookings`, `data`, `payment`, `token`) are retained. Errors use the correlated `error` envelope in the linked contract.

| Step | Request | Result |
| --- | --- | --- |
| Register | `POST /api/register` with `name`, `email`, `password` (8+ characters) | Customer and bearer token; 201 |
| Login | `POST /api/login` with `email`, `password` | Fresh bearer token; 200 |
| Discover | `GET /api/resources` | Active resources, prices in EGP and capacity; paginated |
| Availability | `GET /api/resources/{id}/availability?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD&timezone=Africa/Cairo` | Active future slots, inclusive dates, ISO timestamps with timezone offsets |
| Reserve | `POST /api/bookings` with `resource_id`, `slot_id`, `type: one-on-one` and `Idempotency-Key: <unique-key>` | Owned pending booking; 201 |
| View | `GET /api/bookings/{id}` or `GET /api/bookings` | Owned detail or paginated owned list |
| Checkout | `POST /api/bookings/{id}/pay` | Paymob redirect URL and payment key; booking stays pending |
| Confirm | Verified Paymob authorization callback, then capture worker | Booking becomes confirmed; status history and queued confirmation after commit |
| Cancel | `POST /api/bookings/{id}/update` with `status: canceled` | Reservation canceled and inventory released |

`POST /api/bookings/{id}/update` with `status: confirmed` also starts checkout for a pending booking. Customers may request confirmation/payment or cancellation only. Structural changes, ownership transfers, rescheduling and customer completion are rejected. The domain service allows trusted internal `confirmed -> completed`; no staff-role API is provided. Canceled/completed states are terminal; repeated identical states have no new effects. Invalid transitions return 409.

Slots are shared inventory under the existing global one-to-one unique-slot safeguard: an occupied slot cannot be used by a different resource. Operators must provision canonical, non-overlapping slot IDs; this MVP does not infer conflicts between independently provisioned overlapping rows. Availability is advisory; creation rechecks inventory under the per-slot Redis lock and database row locks. All booking cache views are invalidated after commit. Expired starts are filtered even from cached results.

An identical booking request with the same customer/key replays the original 201 body, including its original pending status; read the detail endpoint for current status. A changed payload with that key returns 422. Missing keys return 400; failures roll back the reservation and key. Keys are pruned after 24 hours, so use a new key for a genuinely new booking, not a retry beyond the replay window.

Group bookings use `type: group`; capacity comes from the resource, never a customer-supplied larger limit. Add `type=group` to availability to discover slots with remaining seats. Each customer may hold one active seat in a group slot; each seat starts pending and is paid individually. Exclusive/recurring bookings and groups on other resources cannot share that slot.

Recurring bookings add `type: recurring`, `recurrence_rule: weekly|biweekly|monthly`, `start_date`, `end_date`, `start_time` and `end_time` (`H:i`). The selected slot must match the first occurrence; every occurrence must already exist and be active. Creation locks and reserves all occurrences atomically, returning the first booking. Use the owned booking list to view/pay/cancel the individual occurrences. Series-wide checkout and cancellation are deferred. Date ranges are capped at one year.

## Paymob and operations

Set merchant test credentials `PAYMOB_API_KEY`, `PAYMOB_INTEGRATION_ID`, `PAYMOB_IFRAME_ID`, `PAYMOB_SECRET_KEY`, `PAYMOB_HMAC`, and `PAYMOB_BASE_URL=https://accept.paymob.com`. Configure the merchant's authorization callback to the HTTPS URL `https://<host>/api/payments/paymob/webhook`. Use the authorization/capture flow expected by this integration. The explicit API webhook works without `PAYMOB_WEBHOOK_URL`; a configured legacy package route delegates to the same application handler. Keep legacy callbacks under `/api/` for JSON error handling.

The callback verifies HMAC, integration, EGP currency and the persisted amount. Receipt storage does not suppress a retry after failed queue delivery. Capture uses the persisted payment and transaction, runs outside a database transaction, then atomically records capture and transitions the booking. Duplicate callbacks/jobs do not send another confirmation. Payment keys and checkout bearer URLs are returned only to the owner and are not persisted.

A second checkout and cancellation are blocked while a payment is processing. Unfinished or declined checkouts require operator reconciliation against Paymob before marking the local processing payment failed and permitting retry/cancellation. Do not clear processing records based only on age. Refunds and cancellation fees are not automated: cancellation releases the reservation; any captured money requires merchant reconciliation/refund separately. Do not present cancellation as a refund.

A timeout after the gateway captured but before local persistence is an ambiguous payment outcome. Verify the transaction in Paymob before retrying the failed capture job; external exactly-once capture cannot be guaranteed by local database locks. Live merchant callbacks and refund/reconciliation policy require staging validation before accepting real money.

Use `DB_QUEUE_RETRY_AFTER=180` (or `REDIS_QUEUE_RETRY_AFTER=180` with Redis queues), exceeding the 120-second capture job timeout. Workers must consume both `bookings` and `default`; restart them with `php artisan queue:restart` after deployment. The scheduler prunes idempotency keys hourly, dispatches reminders every 15 minutes and cleans documents daily at 03:00, using database scheduler locks. Configure cron to run `php artisan schedule:run` every minute in deployment. Inspect `php artisan queue:failed`, resolve the cause, then `php artisan queue:retry <uuid>`; set `FAILED_JOB_ALERT_MAIL_TO` for alerts. Notifications remain at-least-once across worker crashes.

Document endpoints require a configured private `documents` disk (see `config/filesystems.php`), `AWS_*` credentials and bucket permissions for upload/read/delete. Owners upload an `attachment` to `POST /api/bookings/{id}/documents` and request a short-lived URL via `GET /api/bookings/booking-documents/{id}/temporary-url`. S3 integration can be omitted from a booking-only local demo; it has isolated fake-storage tests.

Deployment configuration must set `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`, secure cookies, real mail and private storage credentials. Back up existing data before migrations, run `php artisan migrate --force`, `php artisan config:cache`, restart supervised workers, and verify `/up`. No new migration is introduced by this MVP change.

## Quality checks

```bash
php vendor/bin/pint --test
php vendor/bin/phpstan analyse --no-progress --memory-limit=1G
composer test
php artisan test tests/Feature/MvpJourneyTest.php
composer audit --locked
npm audit
```

CI additionally runs Pest coverage with Xdebug and an 80% minimum. Local test configuration isolates data in in-memory SQLite and uses fake gateway/storage/notifications; passing tests do not certify MySQL interprocess locking, live Paymob or S3. See the demo note for the actual verification results and deferred staging checks.
