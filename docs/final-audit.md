# Final quality audit

Date: 2026-10-19. Scope: one repository quality pass; PHP 8.4.25, Composer 2.9.4, Node 24.19.0. No deployment or application-data migration was performed.

## Evidence and commands

| Command/check | Initial result | Final result |
| --- | --- | --- |
| `vendor/bin/pint --test` | Passed | Passed after running `vendor/bin/pint` on fixes |
| `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | No errors, level 5 | No errors |
| `composer test` | 138 passed, 1,285 assertions | 148 passed, 1,314 assertions on locked dependencies |
| `php artisan test --compact` | Confirmed initial 138 passing tests | Regression tests included in full suite |
| `composer audit --locked --format=json` | Restricted network prevented Packagist access | With network access: no advisories; no abandoned packages |
| `npm.cmd audit --json` | ENOLOCK; PowerShell also blocks the npm.ps1 wrapper | After generating lockfile: zero vulnerabilities at all severities |
| `npm.cmd install --package-lock-only --ignore-scripts --cache .npm-audit-cache` | No npm lockfile existed | Succeeded; committed-candidate package-lock.json uses registry.npmjs.org; temporary cache removed |
| `composer validate --strict` | Valid schema; warning for exact S3 adapter constraint | Exit 1 due to existing `league/flysystem-aws-s3-v3: 3.0` warning; retained |
| `composer install --dry-run --no-scripts --no-interaction --prefer-dist` | Found 23 version differences and 2 extra installed packages | Installed committed lock with `composer install --no-interaction --prefer-dist`, then repeated dry run |
| `php artisan route:list --path=api --except-vendor` | Reviewed API surface | 10 application routes; debug route removed |
| `php artisan event:list` | Duplicate notification and failed-job registrations; missing concrete registrations for generic status listeners | Explicit registrations; tested once per application side effect |
| `git -c safe.directory=D:/bookingsystem diff --check` | Clean | Clean |

Git commands use a per-command safe.directory exception because sandbox ownership differs; global Git configuration was not changed. An attempted `composer test -- --compact` failed because Composer forwarded the option to config:clear; the normal `composer test` command was rerun successfully. Initial network and shell-wrapper failures were not treated as passing audits.

The initial vendor tree was not reproducible: Laravel 13.35.0 instead of locked 13.33.0, other newer transitive dependencies, and extra Horizon. Composer install restored locked versions without changing composer.lock. Install emitted test-helper PSR-4 warnings and pre-uninstall event warnings; package discovery completed successfully. Test helpers are loaded by the test runner and are not production autoload dependencies.

## Findings and fixes, ranked

### Security

- **High, fixed:** authenticated booking lists returned every customer's bookings and identity information. Controller now supplies the authenticated customer ID through service and repository for both normal and local profiling paths. Pagination totals are scoped too. Regression tests cover both paths.
- **High, fixed:** customer updates accepted ownership, resource, slot, booking type and capacity changes without the creation availability checks, and allowed customers to mark completed. This endpoint now accepts status changes only; structural fields and customer completion return 422. Existing payment-start behavior for pending-to-confirmed remains.
- **High, fixed:** redirect URLs persisted payment bearer tokens even though payment_key was removed. Both token-bearing fields are now excluded from saved payment payloads; response still supplies the URL to the customer. Existing stored URLs are not automatically scrubbed by this pass; operators should expire/redact historical payment tokens according to retention policy.
- **Medium, fixed:** login/register had no application throttle. Both now enforce six requests per minute using Laravel's IP-based default throttle key for anonymous clients. Tune this for deployment traffic and proxy configuration.
- **Medium, fixed:** profiling command could create tokens and issue workload against production. Restricted to local/testing and corrected obsolete singular booking-list URLs.
- **Medium, fixed:** unbounded date ranges and incomplete recurring/group payloads could cause excessive work or server errors. Availability/recurrence now cap ranges at one year; group capacity and recurring rule, dates and times are conditionally required.

### Correctness

- **High, fixed:** cache creation lock was released before the surrounding idempotency transaction committed. Creation now also locks the selected slot row inside a database transaction; that lock lasts through the outer commit on databases supporting row locks. SQLite tests cannot demonstrate MySQL interprocess locking.
- **High, fixed:** default event discovery and explicit registration duplicated confirmation notifications and queue failure alerts. Generic object listeners were not connected to concrete status events. Discovery is now disabled and all three status events explicitly register history, cache invalidation and log listeners. Confirmation notification and failed-job alert register once. Registration regression checks and existing rollback/status tests pass.
- **Medium, fixed:** unchanged statuses dispatched new status events. No-op status writes now return without emitting another transition.
- **Medium, fixed:** a missing idempotency key previously executed the controller inside a transaction and then rolled it back. It now validates without booking writes or side effects and returns a correlated JSON error. Payload mismatch uses the same validation envelope; oversized keys are rejected. A stale-cache test now sends the required key so it actually reaches the booking conflict check.

### Maintainability and cleanup

- **High, fixed:** primary CI YAML contained a named PHPStan step without a run action. Restored actual analysis, added Composer audit, added npm audit using the new lockfile, and made staging depend on that audit job.
- **Low, fixed:** removed unused public `/api/test` route and invalid interface-to-resolver binding (resolver does not implement the bound strategy interface).
- Reviewed routes, controllers, requests, services, repositories/interfaces, strategies/factories, models, middleware, events, jobs, listeners, providers, observers and commands. Creation orchestration remains in services; queries remain in repositories except existing strategy/model integration code. No broad responsibility rewrite was attempted.
- No `dd`, `dump`, `var_dump`, `print_r`, TODO or FIXME matches in app/routes. Structured booking/queue logs are operational behavior, not removed as debug residue.
- Tracked-file inventory contains no IDE directories, vendor, node_modules, environment secrets, generated build output or test caches. `.gitignore` already excludes these. Existing docs/profiling JSON files are intentional benchmark evidence and remain.
- Pattern scan of tracked files for AWS access IDs, private key headers, GitHub tokens and live Stripe tokens returned no candidates. Git history path check for `.env`, `.env.production`, auth.json, PEM and key files returned no paths. This is a scoped scan, not proof that every historical commit is secret-free. No secret values were printed.
- Reviewed direct dependencies: Sanctum serves authentication; S3 adapter serves documents; Paymob serves payments; frontend packages are referenced by Vite/CSS/dev scripts. Tinker and Pail remain intentional developer tooling. Exact S3/Paymob constraints were retained to avoid an untested dependency upgrade. Existing factory and strategy implementations overlap but have separate test consumers and different contracts; consolidation deferred as unrelated refactoring.
- Existing untracked `docs/booking-list-query-plan.sql`, `docs/owasp-risk-mapping.md` and `portfolio-deploy/` were present before this pass and were left untouched. Only audit-owned temporary output/cache was removed.

## Static analysis exceptions

None added or accepted. `phpstan-baseline.neon` contains only `parameters:` and no ignored errors. Neither baseline nor level was changed. Existing coverage exclusion for the local profiling command remains: it is a telemetry workload tool, not production request behavior; its environment guard is reviewed but coverage was not recomputed locally.

## Accepted limitations and handover risks

These are explicit limits of this pass, not claims of independently approved business risk:

- Live GitHub run status was not fetched. Both workflow files were reviewed locally. The second workflow retains duplicate style/analysis jobs and its coverage job; removing it would change coverage policy. PHPUnit forces SQLite even in that workflow's MySQL service environment, so a green run does not prove MySQL concurrency behavior.
- Local tests use in-memory SQLite, fake storage/notifications/payment clients and array cache. Real MySQL/Redis concurrent contention, S3 permissions and live Paymob webhook/capture must be exercised in staging. No live external payment or destructive cleanup was attempted.
- Queue effects remain at-least-once: reminders can duplicate if a worker crashes after notification but before marking sent. The scheduler overlap lock protects dispatch, not the entire queued job. Exactly-once delivery would require an outbox/provider idempotency design. Notification transport idempotency is outside this bounded pass.
- Existing domain semantics remain: group capacity comes from the request, group/recurring can be confirmed at creation, and recurring generation locks only the initially selected slot via the new service lock. Recurring generated slots and capacity across competing booking types need a unified inventory/price policy and concurrent integration tests before relying on those modes at production scale. This is not certified by passing one-to-one tests.
- Existing payment lifecycle can race with cancellation and external payment completion; merchant refund/reconciliation policy and concurrent capture/cancel tests remain release prerequisites for live paid operation. Package webhook signature checks were inspected, not exercised against Paymob. `routes/paymob.php` is a published copy; package provider loads its own route. Set and verify PAYMOB_WEBHOOK_URL/HMAC in staging.
- API cancellation currently uses the status transition path, not the separate fee-calculation service. Cancellation fees/refunds must not be assumed to be enforced by this endpoint. Business policy integration is a release decision, not silently invented here.
- Existing `Booking` fillable includes id/internal fields; public request allowlists do not expose those fields and creation overrides customer_id from authentication. Internal callers remain trusted. A broad model contract change was deferred.
- Production environment must explicitly set APP_ENV=production, APP_DEBUG=false, HTTPS APP_URL, secure session cookies, least-privilege database/S3 credentials, real mail transport, Redis and a supervised worker consuming both default and bookings queues. Telescope remains opt-in with an authenticated allowlist. `.env.example` is development configuration, not a production environment file. Secrets were not read from local `.env`.
- The referenced booking-list SQL is a synthetic denormalized index experiment, not the application's customer-scoped, id-sorted list query. Its recorded 2026-10-15 measurements were not rerun or presented as new evidence. No speculative index migration was added.

The code quality gates below pass; live paid/group/recurring production sign-off remains conditional on the domain and staging prerequisites above. This report does not declare those unresolved risks accepted on the owner's behalf.

## Final verification

After installing the exact committed Composer lock: Pint passed; PHPStan reported no errors; composer test passed all 148 tests with 1,314 assertions in 4.84 seconds; repeated Composer dry run reported nothing to install, update or remove. Final diff whitespace check passed. Composer/npm audit results above apply to the lockfiles. Reviewed final tracked diff and new regression test/lockfile/report; no baseline expansion, unrelated refactoring, commits or deployment. Audit-owned temporary logs/cache removed.


## Follow-up: restored update request

At the owner's request, UpdateBookingRequest was restored to its pre-audit rules. The earlier security finding claiming structural fields/customer completion are prohibited is therefore superseded: these fields again pass validation when valid, and validated fields are passed to updateExistingBooking. The regression test now checks invalid values rather than the reverted prohibition, plus a status-only update preserving associations. This does not certify arbitrary structural updates as safe; availability/ownership safeguards for those updates remain an open finding. A pending-to-completed domain transition also remains rejected by the service, without a dedicated API exception mapping.

Verification after this test adjustment: `vendor/bin/pint tests/Feature/FinalAuditRegressionTest.php` passed; `php artisan test --compact` passed 149 tests with 1,317 assertions; `git diff --check` passed. UpdateBookingRequest was not modified during the test fix.

## Follow-up: isolate fake document storage

The reported Windows UnableToDeleteFile referenced recent-orphaned.pdf from a different test while the missing-object cleanup test was running. The exact OS permission/locking cause was not reproduced locally; a shared fake documents root was confirmed in all nine storage tests. Added TestCase::fakeDocumentsDisk() with a UUID-named local fake per test, aliased as documents, preserving configured throwing behavior and temporary URL support. Each test cleans only its own root at teardown. Updated document upload, temporary URL and cleanup tests to use it; production cleanup and UpdateBookingRequest are unchanged. Existing shared-root files are left untouched.

Validation: Pint passed for the four edited test files. Cleanup tests passed (3 tests, 15 assertions). Full suite and a separate cleanup test process were then run concurrently; both passed (149 tests/1,317 assertions and 3 tests/15 assertions respectively).
