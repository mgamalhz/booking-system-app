<?php

use App\Jobs\SendBookingConfirmation;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
});

test('it dispatches booking confirmation job after a booking is confirmed', function () {
    Queue::fake();
    $customer = Customer::factory()->create();

    $booking = Booking::factory()->create([
        'customer_id' => $customer->id,
        'status' => 'pending',
    ]);

    $this->actingAs($customer, 'sanctum')
        ->post(route('bookings.update', $booking), [
            'status' => 'confirmed',
        ])->assertOk();

    Queue::assertPushed(SendBookingConfirmation::class, function (SendBookingConfirmation $job) use ($booking) {
        return $job->booking->is($booking)
            && $job->queue === 'bookings'
            && $job->afterCommit === true
            && $job->tries === 3
            && $job->backoff === [10, 30, 60];
    });
});

test('it creates api bookings through the service as pending and does not dispatch confirmation', function () {
    Queue::fake();
    $customer = Customer::factory()->create();

    $response = $this->actingAs($customer, 'sanctum')->postJson(route('bookings.store'), [
        'customer_id' => Customer::factory()->create()->id,
        'resource_id' => Resource::factory()->create()->id,
        'slot_id' => Slot::factory()->create()->id,
        'status' => 'confirmed',
        'type' => 'one-on-one',
    ], [
        'Idempotency-Key' => 'creates-api-booking',
    ])->assertCreated();

    $booking = Booking::query()->findOrFail($response->json('booking.id'));

    expect($booking->status)->toBe('pending');
    expect($booking->customer_id)->toBe($customer->id);
    Queue::assertNotPushed(SendBookingConfirmation::class);
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
    Queue::fake();
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
    ], [
        'Idempotency-Key' => 'rejects-unavailable-slot',
    ])
        ->assertConflict()
        ->assertJsonPath('error.message', 'The selected slot is no longer available.');

    expect(Booking::query()->where('slot_id', $slot->id)->count())->toBe(1);
    Queue::assertNotPushed(SendBookingConfirmation::class);
});
