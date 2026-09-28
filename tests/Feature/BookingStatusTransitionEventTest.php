<?php

use App\Events\BookingCancelled;
use App\Events\BookingCompleted;
use App\Events\BookingConfirmed;
use App\Exceptions\InvalidBookingStatusTransition;
use App\Listeners\BookingConfirmationNotificationListener;
use App\Listeners\InvalidateBookingAvailabilityCache;
use App\Listeners\LogConfirmedBooking;
use App\Listeners\RecordBookingStatusEvent;
use App\Models\Booking;
use App\Notifications\BookingConfirmationNotification;
use App\Services\BookingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);
});

test('rolled back status transitions do not dispatch booking status events', function () {
    Event::fake([BookingConfirmed::class, BookingCancelled::class, BookingCompleted::class]);

    $booking = Booking::factory()->create(['status' => 'pending']);
    $service = app(BookingService::class);

    try {
        DB::transaction(function () use ($booking, $service) {
            $service->transitionStatus($booking, 'confirmed');

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
    }

    Event::assertNothingDispatched();
    expect($booking->fresh()->status)->toBe('pending');
});

test('invalid booking status transitions throw a domain exception', function () {
    $booking = Booking::factory()->create(['status' => 'canceled']);

    expect(fn () => app(BookingService::class)->transitionStatus($booking, 'confirmed'))
        ->toThrow(InvalidBookingStatusTransition::class);
});

test('successful status transitions dispatch explicit past tense events', function (string $from, string $to, string $eventClass) {
    Event::fake([$eventClass]);

    $booking = Booking::factory()->create(['status' => $from]);

    DB::transaction(fn () => app(BookingService::class)->transitionStatus($booking, $to));

    Event::assertDispatched($eventClass, fn ($event) => $event->bookingId === $booking->id
        && $event->customerId === $booking->customer_id
        && $event->slotId === $booking->slot_id
        && $event->resourceId === $booking->resource_id
        && $event->fromStatus === $from
        && $event->toStatus === $to);
})->with([
    ['pending', 'confirmed', BookingConfirmed::class],
    ['pending', 'canceled', BookingCancelled::class],
    ['confirmed', 'completed', BookingCompleted::class],
]);

test('status history listener records the reconstructable timeline', function () {
    $booking = Booking::factory()->create(['status' => 'confirmed']);
    $event = new BookingCompleted(
        $booking->id,
        $booking->customer_id,
        $booking->slot_id,
        $booking->resource_id,
        'confirmed',
        'completed',
        now()->toISOString(),
    );

    (new RecordBookingStatusEvent)->handle($event);

    $this->assertDatabaseHas('booking_status_events', [
        'booking_id' => $booking->id,
        'from_status' => 'confirmed',
        'to_status' => 'completed',
        'event_type' => 'BookingCompleted',
    ]);
});

test('availability cache listener invalidates slot and resource availability keys', function () {
    $booking = Booking::factory()->create(['status' => 'pending']);
    Cache::put("slot:{$booking->slot_id}:availability", true);
    Cache::put("resource:{$booking->resource_id}:availability", true);

    (new InvalidateBookingAvailabilityCache)->handle(new BookingConfirmed(
        $booking->id,
        $booking->customer_id,
        $booking->slot_id,
        $booking->resource_id,
        'pending',
        'confirmed',
        now()->toISOString(),
    ));

    expect(Cache::has("slot:{$booking->slot_id}:availability"))->toBeFalse()
        ->and(Cache::has("resource:{$booking->resource_id}:availability"))->toBeFalse();
});

test('confirmation notification listener sends the notification and is queued', function () {
    Notification::fake();

    $booking = Booking::factory()->create(['status' => 'confirmed']);
    $event = new BookingConfirmed(
        $booking->id,
        $booking->customer_id,
        $booking->slot_id,
        $booking->resource_id,
        'pending',
        'confirmed',
        now()->toISOString(),
    );

    $listener = new BookingConfirmationNotificationListener;
    $listener->handle($event);

    expect($listener)->toBeInstanceOf(ShouldQueue::class);
    Notification::assertSentTo($booking->customer, BookingConfirmationNotification::class);
});

test('audit log listener logs the status change and is queued', function () {
    Log::shouldReceive('info')->once()->with('Booking status changed', Mockery::on(function (array $context) {
        return $context['booking_id'] === 123
            && $context['from_status'] === 'pending'
            && $context['to_status'] === 'confirmed';
    }));

    $listener = new LogConfirmedBooking;
    $listener->handle(new BookingConfirmed(123, 456, 789, 101, 'pending', 'confirmed', now()->toISOString()));

    expect($listener)->toBeInstanceOf(ShouldQueue::class);
});
