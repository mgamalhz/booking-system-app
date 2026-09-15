<?php

use App\Factories\BookingFactory;
use App\Factories\GroupBookingFactory;
use App\Factories\OneToOneBookingFactory;
use App\Factories\RecurringBookingFactory;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_factory_returns_correct_booking_factory_class()
    {
        $data = [
            'type' => 'one-on-one',
            'slot_id' => 1,
            'customer_id' => 1,
        ];

        $bookingFactoryClass = BookingFactory::resolve($data['type']);

        $this->assertInstanceOf(OneToOneBookingFactory::class, $bookingFactoryClass);
    }

    public function test_booking_factory_returns_correct_booking_factory_class_for_group_booking()
    {
        $data = [
            'type' => 'group',
            'slot_id' => 1,
            'customer_id' => 1,
        ];

        $bookingFactoryClass = BookingFactory::resolve($data['type']);
        $this->assertInstanceOf(GroupBookingFactory::class, $bookingFactoryClass);
    }

    public function test_booking_factory_returns_correct_booking_factory_class_for_recurring_booking(): void
    {
        $bookingFactoryClass = BookingFactory::resolve('recurring');

        $this->assertInstanceOf(RecurringBookingFactory::class, $bookingFactoryClass);
    }

    public function test_booking_factory_rejects_unsupported_booking_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported booking type');

        BookingFactory::resolve('unsupported');
    }

    public function test_one_to_one_booking_factory_creates_booking_for_available_slot(): void
    {
        $data = [
            'type' => 'one-on-one',
            'status' => 'pending',
            'slot_id' => Slot::factory()->create()->id,
            'customer_id' => Customer::factory()->create()->id,
            'resource_id' => Resource::factory()->create()->id,
        ];

        $booking = (new OneToOneBookingFactory)->create($data);

        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertDatabaseHas('bookings', $data);
    }

    public function test_one_to_one_booking_factory_rejects_unavailable_slot(): void
    {
        $slot = Slot::factory()->create();

        Booking::factory()->create([
            'slot_id' => $slot->id,
            'status' => 'confirmed',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Slot is not available');

        (new OneToOneBookingFactory)->create([
            'slot_id' => $slot->id,
            'customer_id' => Customer::factory()->create()->id,
            'resource_id' => Resource::factory()->create()->id,
        ]);
    }

    public function test_one_to_one_booking_factory_rejects_multiple_customers(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('More one Customer in this type is not available');

        (new OneToOneBookingFactory)->create([
            'slot_id' => [Slot::factory()->create()->id],
            'customer_id' => [
                Customer::factory()->create()->id,
                Customer::factory()->create()->id,
            ],
            'resource_id' => Resource::factory()->create()->id,
        ]);
    }

    public function test_one_to_one_booking_factory_rejects_multiple_slots(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('More one Slot in this type is not available');

        (new OneToOneBookingFactory)->create([
            'slot_id' => [
                Slot::factory()->create()->id,
                Slot::factory()->create()->id,
            ],
            'customer_id' => [Customer::factory()->create()->id],
            'resource_id' => Resource::factory()->create()->id,
        ]);
    }

    public function test_group_booking_factory_creates_pending_and_confirmed_bookings(): void
    {
        $slot = Slot::factory()->create();

        $pending = (new GroupBookingFactory)->create([
            'slot_id' => $slot->id,
            'customer_id' => Customer::factory()->create()->id,
            'resource_id' => Resource::factory()->create()->id,
            'min_participants' => 2,
            'max_participants' => 3,
        ]);

        $confirmed = (new GroupBookingFactory)->create([
            'slot_id' => $slot->id,
            'customer_id' => Customer::factory()->create()->id,
            'resource_id' => Resource::factory()->create()->id,
            'min_participants' => 2,
            'max_participants' => 3,
        ]);

        $this->assertSame('pending', $pending->status);
        $this->assertSame('confirmed', $confirmed->status);
    }

    public function test_group_booking_factory_rejects_missing_and_full_capacity(): void
    {
        $factory = new GroupBookingFactory;
        $slot = Slot::factory()->create();

        try {
            $factory->create([
                'slot_id' => $slot->id,
                'customer_id' => Customer::factory()->create()->id,
            ]);

            $this->fail('Expected missing max participants exception was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame('Max participants is required for group booking.', $exception->getMessage());
        }

        Booking::factory()->create([
            'slot_id' => $slot->id,
            'type' => 'group',
            'status' => 'confirmed',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Group booking is full.');

        $factory->create([
            'slot_id' => $slot->id,
            'customer_id' => Customer::factory()->create()->id,
            'resource_id' => Resource::factory()->create()->id,
            'max_participants' => 1,
        ]);
    }

    public function test_recurring_booking_factory_creates_weekly_bookings(): void
    {
        Slot::factory()->create([
            'date' => '2026-08-03',
            'start_time' => '09:00:00',
        ]);
        Slot::factory()->create([
            'date' => '2026-08-10',
            'start_time' => '09:00:00',
        ]);

        $booking = (new RecurringBookingFactory)->create([
            'slot_id' => Slot::factory()->create()->id,
            'customer_id' => Customer::factory()->create()->id,
            'resource_id' => Resource::factory()->create()->id,
            'start_date' => '2026-08-03',
            'end_date' => '2026-08-10',
            'start_time' => '09:00:00',
            'recurrence_rule' => 'weekly',
        ]);

        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertSame(2, Booking::query()->where('type', 'recurring')->count());
    }

    public function test_recurring_booking_factory_rejects_missing_data_booked_slots_and_invalid_rules(): void
    {
        $factory = new RecurringBookingFactory;

        try {
            $factory->create([]);

            $this->fail('Expected missing recurring data exception was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame('Recurrence rule and end date are required.', $exception->getMessage());
        }

        $slot = Slot::factory()->create([
            'date' => '2026-08-03',
            'start_time' => '09:00:00',
        ]);

        Booking::factory()->create([
            'slot_id' => $slot->id,
            'status' => 'pending',
        ]);

        try {
            $factory->create([
                'customer_id' => Customer::factory()->create()->id,
                'resource_id' => Resource::factory()->create()->id,
                'start_date' => '2026-08-03',
                'end_date' => '2026-08-03',
                'start_time' => '09:00:00',
                'recurrence_rule' => 'weekly',
            ]);

            $this->fail('Expected booked recurring slot exception was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame('Slot already booked for date 2026-08-03.', $exception->getMessage());
        }

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid recurrence rule.');

        $factory->create([
            'customer_id' => Customer::factory()->create()->id,
            'resource_id' => Resource::factory()->create()->id,
            'start_date' => '2026-08-03',
            'end_date' => '2026-08-03',
            'start_time' => '09:00:00',
            'recurrence_rule' => 'daily',
        ]);
    }
}
