# MVP acceptance checklist

Created before implementation, 2026-10-19. Scope: existing authenticated JSON API; retain the existing payment gateway, locking, idempotency and event architecture.

- [x] Discover active resources and their availability without needing database IDs from an operator.
- [x] Create a booking as the authenticated customer; reject inactive/past inventory and invalid resource/slot combinations.
- [x] Replay identical idempotent requests; reject payload mismatches and occupied slots without duplicate writes.
- [x] View a single owned booking and list only owned bookings; reject anonymous and cross-owner access.
- [x] Restrict customer updates to supported status actions; preserve ownership and inventory; serialize transitions and map invalid transitions to JSON conflicts.
- [x] Keep payment initiation pending; capture confirms once through domain events and queued post-commit confirmation; do not resurrect terminal bookings.
- [x] Keep group/recurring creation consistent with inventory conflicts and payment requirements.
- [x] Use consistent JSON success markers and existing correlated error envelopes throughout the MVP API.
- [x] Provide reproducible setup, migrations, demo inventory, queue/scheduler and payment configuration instructions.
- [x] Run existing quality gates and an automated complete user walkthrough; record deferred operational validation honestly.

Initial evidence: routes/api.php has no resource list or booking detail route; UpdateBookingRequest accepts structural changes; InvalidBookingStatusTransition has no API mapping; Booking::markPaymobCaptured directly writes status; group/recurring strategies can confirm without payment; README is framework boilerplate. Existing untracked SQL and OWASP documents are outside this change.

## Acceptance evidence

All implementation items above are complete for the API MVP. See [demo and verification](mvp-demo.md) for the executable walkthrough, quality results and explicit staging/business-policy deferrals; see [setup](../README.md) for migration, queue, merchant and demo steps.

The existing global slot reservation contract is retained. Discovery now follows that contract and invalidates all affected cache views. Group capacity is server-owned; recurring creation reserves pre-existing slots with current locking reads and rolls the series back on any conflict. Every created booking stays pending until payment capture.

The customer endpoint now accepts only confirmation/payment or cancellation. Trusted service transitions lock and re-read persisted state, publish after commit and preserve terminal states. Capture uses one domain-event-to-job confirmation path. Application webhook/capture handling validates signed payment facts, retries queue delivery even for a recorded receipt, and suppresses duplicate capture effects.

No baseline expansion, schema redesign or unrelated UI work. Local gates passed; live provider and interprocess staging checks are explicitly deferred, not certified by SQLite tests.
