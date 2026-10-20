<?php

use App\Events\BookingCancelled;
use App\Events\BookingCompleted;
use App\Events\BookingConfirmed;
use App\Listeners\SendFailedJobAlert;
use App\Models\Booking;
use App\Models\Customer;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('limits both booking list paths to the authenticated customer', function (bool $profile) {
    $customer = Customer::factory()->create();
    $own = Booking::factory()->create(['customer_id' => $customer->id]);
    Booking::factory()->create();

    $this->actingAs($customer, 'sanctum')
        ->getJson(route('bookings.index', ['profile_bottleneck' => $profile]))
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id)->assertJsonPath('meta.total', 1);
})->with([false, true]);

it('rejects invalid booking update values', function (string $field) {
    $booking = Booking::factory()->create(['status' => 'pending']);
    $value = match ($field) {
        'status' => 'invalid-status',
        'type' => 'invalid-type',
        default => 0,
    };

    $this->actingAs($booking->customer, 'sanctum')
        ->postJson(route('bookings.update', $booking), [$field => $value])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    expect($booking->refresh()->status)->toBe('pending');
})->with(['customer_id', 'slot_id', 'resource_id', 'type', 'status']);

it('updates only the supplied status and preserves booking associations', function () {
    Event::fake([BookingCancelled::class]);
    $booking = Booking::factory()->create(['status' => 'pending']);
    $associations = $booking->only(['customer_id', 'resource_id', 'slot_id', 'type']);

    $this->actingAs($booking->customer, 'sanctum')
        ->postJson(route('bookings.update', $booking), ['status' => 'canceled'])
        ->assertOk()->assertJsonPath('booking.status', 'canceled');

    expect($booking->refresh()->only(array_keys($associations)))->toBe($associations);
});

it('does not publish a second event for an unchanged status', function () {
    Event::fake([BookingCancelled::class]);
    $booking = Booking::factory()->create(['status' => 'canceled']);
    app(BookingService::class)->updateExistingBooking($booking, ['status' => 'canceled']);
    Event::assertNotDispatched(BookingCancelled::class);
});

it('registers each event side effect once', function () {
    $listeners = app('events')->getRawListeners();
    expect($listeners[BookingConfirmed::class])->toHaveCount(4)
        ->and(array_values(array_filter($listeners[JobFailed::class], fn ($listener) => is_array($listener) && $listener[0] === SendFailedJobAlert::class)))->toHaveCount(1)
        ->and($listeners[BookingCancelled::class])->toHaveCount(3)
        ->and($listeners[BookingCompleted::class])->toHaveCount(3);
});

it('rejects excessive availability ranges', function () {
    $customer = Customer::factory()->create();
    $resource = App\Models\Resource::factory()->create();
    $this->actingAs($customer, 'sanctum')->getJson(route('resources.availability', [
        'resource' => $resource, 'start_date' => '2030-01-01', 'end_date' => '2032-01-01', 'timezone' => 'UTC',
    ]))->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
});
