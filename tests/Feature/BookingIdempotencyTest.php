<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    config()->set('cache.default', 'array');
});

function idempotentBookingPayload(Customer $customer, Resource $resource, Slot $slot): array
{
    return [
        'customer_id' => $customer->id,
        'resource_id' => $resource->id,
        'slot_id' => $slot->id,
        'type' => 'one-on-one',
    ];
}

test('it requires an idempotency key to create a booking', function () {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.store'), idempotentBookingPayload(
            $customer,
            Resource::factory()->create(),
            Slot::factory()->create(),
        ))
        ->assertStatus(400)
        ->assertJson([
            'message' => 'The Idempotency-Key header is required.',
        ]);

    expect(Booking::query()->count())->toBe(0);
});

test('an identical idempotency retry creates one booking and replays the same body', function () {
    $customer = Customer::factory()->create();
    $payload = idempotentBookingPayload(
        $customer,
        Resource::factory()->create(),
        Slot::factory()->create(),
    );

    $first = $this->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.store'), $payload, ['Idempotency-Key' => 'retry-once'])
        ->assertCreated();

    $second = $this->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.store'), $payload, ['Idempotency-Key' => 'retry-once'])
        ->assertCreated();

    expect(Booking::query()->count())->toBe(1)
        ->and($second->getContent())->toBe($first->getContent());
});

test('the same idempotency key with a different payload returns unprocessable without another booking', function () {
    $customer = Customer::factory()->create();
    $resource = Resource::factory()->create();
    $slot = Slot::factory()->create();
    $payload = idempotentBookingPayload($customer, $resource, $slot);

    $this->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.store'), $payload, ['Idempotency-Key' => 'changed-payload'])
        ->assertCreated();

    $changedPayload = array_merge($payload, [
        'slot_id' => Slot::factory()->create()->id,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.store'), $changedPayload, ['Idempotency-Key' => 'changed-payload'])
        ->assertUnprocessable()
        ->assertJson([
            'message' => 'The Idempotency-Key header was already used with a different payload.',
        ]);

    expect(Booking::query()->count())->toBe(1)
        ->and(Booking::query()->where('slot_id', $slot->id)->count())->toBe(1);
});

test('two different idempotency keys for the same slot still create exactly one booking', function () {
    $firstCustomer = Customer::factory()->create();
    $secondCustomer = Customer::factory()->create();
    $resource = Resource::factory()->create();
    $slot = Slot::factory()->create();

    $this->actingAs($firstCustomer, 'sanctum')
        ->postJson(route('bookings.store'), idempotentBookingPayload($firstCustomer, $resource, $slot), [
            'Idempotency-Key' => 'first-key',
        ])
        ->assertCreated();

    $this->actingAs($secondCustomer, 'sanctum')
        ->postJson(route('bookings.store'), idempotentBookingPayload($secondCustomer, $resource, $slot), [
            'Idempotency-Key' => 'second-key',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slot_id');

    expect(Booking::query()->where('slot_id', $slot->id)->count())->toBe(1)
        ->and(Booking::query()->count())->toBe(1);
});
