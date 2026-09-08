<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    config()->set('cache.default', 'array');
    config()->set('booking.lock.wait_seconds', 0);
    config()->set('booking.lock.ttl_seconds', 10);
});

function bookingCreationLockPayload(Customer $customer, Resource $resource, Slot $slot): array
{
    return [
        'customer_id' => $customer->id,
        'resource_id' => $resource->id,
        'slot_id' => $slot->id,
        'type' => 'one-on-one',
    ];
}

test('it returns conflict when another booking attempt already holds the slot lock', function () {
    $customer = Customer::factory()->create();
    $resource = Resource::factory()->create();
    $slot = Slot::factory()->create();
    $lock = Cache::lock("slot:{$slot->id}:book", 10);

    expect($lock->get())->toBeTrue();

    try {
        $this->actingAs($customer, 'sanctum')
            ->postJson(route('bookings.store'), bookingCreationLockPayload($customer, $resource, $slot))
            ->assertConflict()
            ->assertJson([
                'success' => false,
                'message' => 'This slot is currently being booked. Please try again shortly.',
            ]);

        expect(Booking::query()->where('slot_id', $slot->id)->doesntExist())->toBeTrue();
    } finally {
        $lock->release();
    }
});

test('it does not block bookings for different slots while one slot lock is held', function () {
    $customer = Customer::factory()->create();
    $resource = Resource::factory()->create();
    $lockedSlot = Slot::factory()->create();
    $availableSlot = Slot::factory()->create();

    Cache::lock("slot:{$lockedSlot->id}:book", 10)->block(0, function () use ($customer, $resource, $availableSlot) {
        $this->actingAs($customer, 'sanctum')
            ->postJson(route('bookings.store'), bookingCreationLockPayload($customer, $resource, $availableSlot))
            ->assertCreated();
    });

    $this->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.store'), bookingCreationLockPayload($customer, $resource, $lockedSlot))
        ->assertCreated();

    expect(Booking::query()->whereIn('slot_id', [$lockedSlot->id, $availableSlot->id])->count())->toBe(2);
});
