<?php

use App\Events\BookingConfirmed;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);
});

test('it dispatches booking confirmation job after a booking is confirmed', function () {
    Event::fake([BookingConfirmed::class]);
    $customer = Customer::factory()->create();

    $booking = Booking::factory()->create([
        'customer_id' => $customer->id,
        'status' => 'pending',
    ]);

    $this->actingAs($customer, 'sanctum')
        ->post(route('bookings.update', $booking), [
            'status' => 'confirmed',
        ])->assertOk();

    Event::assertDispatched(BookingConfirmed::class, function (BookingConfirmed $event) use ($booking) {
        return $event->bookingId === $booking->id
            && $event->customerId === $booking->customer_id
            && $event->fromStatus === 'pending'
            && $event->toStatus === 'confirmed';
    });
});

test('it creates api bookings through the service as pending and does not dispatch confirmation', function () {
    Event::fake([BookingConfirmed::class]);
    $customer = Customer::factory()->create();

    $response = $this->actingAs($customer, 'sanctum')->postJson(route('bookings.store'), [
        'customer_id' => Customer::factory()->create()->id,
        'resource_id' => Resource::factory()->create()->id,
        'slot_id' => Slot::factory()->create()->id,
        'status' => 'confirmed',
        'type' => 'one-on-one',
    ])->assertCreated();

    $booking = Booking::query()->findOrFail($response->json('booking.id'));

    expect($booking->status)->toBe('pending');
    expect($booking->customer_id)->toBe($customer->id);
    Event::assertNotDispatched(BookingConfirmed::class);
});

test('it requires authentication to create a booking', function () {
    $this->postJson(route('bookings.store'), [
        'customer_id' => Customer::factory()->create()->id,
        'resource_id' => Resource::factory()->create()->id,
        'slot_id' => Slot::factory()->create()->id,
        'type' => 'one-on-one',
    ])->assertUnauthorized();
});

test('it rejects booking updates from another user', function () {
    $booking = Booking::factory()->create([
        'customer_id' => Customer::factory()->create()->id,
        'status' => 'pending',
    ]);

    $this->actingAs(Customer::factory()->create(), 'sanctum')
        ->postJson(route('bookings.update', $booking), [
            'status' => 'confirmed',
        ])
        ->assertForbidden();
});

test('it rejects api booking creation when the slot is already unavailable', function () {
    Event::fake([BookingConfirmed::class]);
    $customer = Customer::factory()->create();

    $slot = Slot::factory()->create();

    Booking::factory()->create([
        'slot_id' => $slot->id,
        'status' => 'confirmed',
        'type' => 'one-on-one',
    ]);

    $this->actingAs($customer, 'sanctum')->postJson(route('bookings.store'), [
        'customer_id' => Customer::factory()->create()->id,
        'resource_id' => Resource::factory()->create()->id,
        'slot_id' => $slot->id,
        'status' => 'confirmed',
        'type' => 'one-on-one',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slot_id');

    expect(Booking::query()->where('slot_id', $slot->id)->count())->toBe(1);
    Event::assertNotDispatched(BookingConfirmed::class);
});
