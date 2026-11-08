<?php

namespace Tests\Unit;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use App\Strategies\BookingStrategies\OneToOneBookingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingAvailabilityPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_an_already_booked_slot(): void
    {
        $slot = Slot::factory()->create();
        $resource = Resource::factory()->create();

        Booking::factory()->create([
            'slot_id' => $slot->id,
            'resource_id' => $resource->id,
            'status' => 'pending',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Slot is not available');

        (new OneToOneBookingStrategy)->createBooking([
            'type' => 'one-on-one',
            'slot_id' => $slot->id,
            'customer_id' => Customer::factory()->create()->id,
            'resource_id' => $resource->id,
            'status' => 'pending',
        ]);
    }

    public function test_it_finds_a_booked_slot_when_searching_many_bookings(): void
    {
        $customer = Customer::factory()->create();
        $resource = Resource::factory()->create();
        $bookedSlot = Slot::factory()->create();
        $otherSlot = Slot::factory()->create();

        $bookings = [];
        $now = now();

        for ($i = 0; $i < 1000; $i++) {
            $bookings[] = [
                'customer_id' => $customer->id,
                'resource_id' => $resource->id,
                'slot_id' => $otherSlot->id,
                // Historical rows provide volume without impossible active reservations.
                'status' => 'canceled',
                'type' => 'one-on-one',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Booking::insert($bookings);
        Booking::factory()->create([
            'customer_id' => $customer->id,
            'resource_id' => $resource->id,
            'slot_id' => $bookedSlot->id,
            'status' => 'pending',
            'type' => 'one-on-one',
        ]);

        $this->assertSame(1001, Booking::count());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Slot is not available');

        (new OneToOneBookingStrategy)->createBooking([
            'type' => 'one-on-one',
            'slot_id' => $bookedSlot->id,
            'customer_id' => $customer->id,
            'resource_id' => $resource->id,
            'status' => 'pending',
        ]);
    }
}
