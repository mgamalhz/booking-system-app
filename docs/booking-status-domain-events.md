# Booking Status Domain Events

## Summary

Booking status changes now publish past-tense domain events instead of hardwiring side effects into `BookingService`.

Implemented events:

- `BookingConfirmed`
- `BookingCancelled`
- `BookingCompleted`

Each event carries primitives only: booking/customer/slot/resource IDs, previous status, new status, and the occurrence timestamp. No serialized Eloquent model is passed through the event.

## Transition Rules

Allowed transitions:

- `pending -> confirmed`
- `pending -> canceled`
- `confirmed -> canceled`
- `confirmed -> completed`

Terminal states:

- `canceled`
- `completed`

Invalid transitions throw `App\Exceptions\InvalidBookingStatusTransition`. For example, `canceled -> confirmed` is rejected instead of silently updating the column.

## After-Commit Dispatch

The three domain events implement `ShouldDispatchAfterCommit`, so listeners only observe committed status changes. The rollback path is covered by a test that forces a transaction failure and asserts no status event was dispatched.

## Listeners

Synchronous listeners:

- `RecordBookingStatusEvent`: writes to `booking_status_events` so the status timeline can be reconstructed.
- `InvalidateBookingAvailabilityCache`: invalidates slot/resource availability cache keys immediately after commit.

Queued listeners:

- `BookingConfirmationNotificationListener`: sends the confirmation notification and can tolerate delay.
- `LogConfirmedBooking`: writes an audit log entry and can tolerate delay.

`BookingService` no longer references notification, audit logging, or cache invalidation consequences.

## Database

Added `booking_status_events` with:

- `booking_id`
- `customer_id`
- `slot_id`
- `resource_id`
- `from_status`
- `to_status`
- `event_type`
- `occurred_at`

Also added `completed` as a supported booking status.

## Tests

Added/updated tests for:

- rollback does not dispatch after-commit status events
- successful transitions dispatch the correct explicit event
- invalid transitions throw the domain exception
- status history listener writes `booking_status_events`
- availability cache invalidation listener runs
- notification listener runs and is queued
- audit log listener runs and is queued

Focused verification command:

```bash
php artisan test tests\\Feature\\BookingStatusTransitionEventTest.php tests\\Feature\\BookingConfirmationTest.php tests\\Unit\\BookingEventTest.php tests\\Unit\\BookingCancellationServiceTest.php
```

Result: 23 tests passed, 42 assertions.
