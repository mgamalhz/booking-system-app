<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use App\Strategies\BookingStrategies\BookingStrategyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function bookingTypeData(string $type, ?Slot $slot = null): array
{
    return [
        'type' => $type,
        'slot_id' => ($slot ?? Slot::factory()->create())->id,
        'customer_id' => Customer::factory()->create()->id,
        'resource_id' => Resource::factory()->create()->id,
        'status' => 'pending',
    ];
}

test('it creates a one to one booking', function () {
    $data = bookingTypeData('one-on-one');

    $booking = BookingStrategyResolver::resolve('one-on-one')->createBooking($data);

    expect($booking)
        ->toBeInstanceOf(Booking::class)
        ->type->toBe('one-on-one')
        ->status->toBe('pending');

    $this->assertDatabaseHas('bookings', $data);
});

test('it creates a pending group booking until the minimum participants count is reached', function () {
    $data = array_merge(bookingTypeData('group'), [
        'min_participants' => 2,
        'max_participants' => 3,
    ]);

    Resource::query()->findOrFail($data['resource_id'])->update(['capacity' => 3]);
    $booking = BookingStrategyResolver::resolve('group')->createBooking($data);

    expect($booking)
        ->type->toBe('group')
        ->status->toBe('pending')
        ->max_participants->toBe(3);

    $this->assertDatabaseHas('bookings', [
        'id' => $booking->id,
        'type' => 'group',
        'status' => 'pending',
        'max_participants' => 3,
    ]);
});

test('it keeps group bookings pending until payment even when minimum participants is reached', function () {
    $slot = Slot::factory()->create();
    $resource = Resource::factory()->create(['capacity' => 3]);

    $existing = Booking::factory()->create([
        'type' => 'group',
        'slot_id' => $slot->id,
        'resource_id' => $resource->id,
        'status' => 'pending',
    ]);

    $data = array_merge(bookingTypeData('group', $slot), [
        'min_participants' => 2,
        'max_participants' => 3,
    ]);

    $data['resource_id'] = $existing->resource_id;
    $booking = BookingStrategyResolver::resolve('group')->createBooking($data);

    expect($booking->status)->toBe('pending');
});

test('it rejects a group booking when the group is full', function () {
    $slot = Slot::factory()->create();

    $existing = Booking::factory()->create([
        'type' => 'group',
        'slot_id' => $slot->id,
        'status' => 'confirmed',
    ]);

    $data = array_merge(bookingTypeData('group', $slot), [
        'max_participants' => 1,
    ]);

    $data['resource_id'] = $existing->resource_id;
    $existing->resource->update(['capacity' => 1]);
    expect(fn () => BookingStrategyResolver::resolve('group')->createBooking($data))
        ->toThrow(Exception::class, 'Group booking is full.');
});

test('it creates recurring bookings for every date in the recurrence range', function () {
    $data = [
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-15',
        'recurrence_rule' => 'weekly',
        'start_time' => '13:00:00',
        'end_time' => '14:00:00',
        'customer_id' => Customer::factory()->create()->id,
        'resource_id' => Resource::factory()->create()->id,
    ];

    foreach (['2026-07-01', '2026-07-08', '2026-07-15'] as $date) {
        Slot::factory()->create(['date' => $date, 'start_time' => '13:00:00', 'end_time' => '14:00:00']);
    }
    $booking = BookingStrategyResolver::resolve('recurring')->createBooking($data);

    expect($booking)
        ->toBeInstanceOf(Booking::class)
        ->type->toBe('recurring')
        ->status->toBe('pending')
        ->and(Booking::query()->where('type', 'recurring')->count())->toBe(3)
        ->and(Slot::query()
            ->whereDate('date', '2026-07-01')
            ->where('start_time', '13:00:00')
            ->where('end_time', '14:00:00')
            ->exists()
        )->toBeTrue();
});

test('it rolls back recurring booking creation when one generated slot is already booked', function () {
    $customer = Customer::factory()->create();
    $resource = Resource::factory()->create();
    Slot::factory()->create(['date' => '2026-07-01', 'start_time' => '13:00:00', 'end_time' => '14:00:00']);
    $conflictingSlot = Slot::factory()->create([
        'date' => '2026-07-08',
        'start_time' => '13:00:00',
        'end_time' => '14:00:00',
    ]);

    Booking::factory()->create([
        'slot_id' => $conflictingSlot->id,
        'status' => 'confirmed',
    ]);

    $data = [
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-15',
        'recurrence_rule' => 'weekly',
        'start_time' => '13:00:00',
        'end_time' => '14:00:00',
        'customer_id' => $customer->id,
        'resource_id' => $resource->id,
    ];

    expect(fn () => BookingStrategyResolver::resolve('recurring')->createBooking($data))
        ->toThrow(Exception::class, 'Slot already booked for date 2026-07-08.')
        ->and(Booking::query()->where('type', 'recurring')->count())->toBe(0);
});
