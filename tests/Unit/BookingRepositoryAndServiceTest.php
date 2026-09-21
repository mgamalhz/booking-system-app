<?php

namespace Tests\Unit;

use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use App\Repositories\Interfaces\BookingRepositoryInterface;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class BookingRepositoryAndServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Resource $resource;

    protected Customer $customer;

    protected Slot $slot;

    protected array $bookingData;

    protected BookingRepositoryInterface $bookingRepository;

    /**
     * A basic feature test example.
     *
     * @throws BindingResolutionException
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->resource = Resource::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->slot = Slot::factory()->create();
        $this->bookingData = [
            'customer_id' => $this->customer->id,
            'resource_id' => $this->resource->id,
            'slot_id' => $this->slot->id,
            'status' => 'pending',
        ];

        $this->bookingRepository = $this->app->make(BookingRepositoryInterface::class);
    }

    public function test_booking_repository_is_injected_correctly(): void
    {
        $this->assertInstanceOf(BookingRepositoryInterface::class, $this->bookingRepository);
    }

    public function test_booking_service_can_create_booking(): void
    {
        $service = app(BookingService::class);
        $this->app->instance(BookingService::class, $service);
        $booking = $service->createBooking($this->bookingData);
        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertDatabaseHas('bookings', $this->bookingData);
    }

    public function test_booking_repository_can_create_booking(): void
    {
        $booking = $this->bookingRepository->create($this->bookingData);

        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertDatabaseHas('bookings', $this->bookingData);
    }

    public function test_booking_repository_can_find_booking_by_id(): void
    {
        $booking = Booking::factory()->create($this->bookingData);

        $foundBooking = $this->bookingRepository->find($booking->id);

        $this->assertTrue($foundBooking->is($booking));
    }

    public function test_booking_repository_can_find_booking_by_column(): void
    {
        Booking::factory()->create($this->bookingData);
        $confirmedBooking = Booking::factory()->create(
            array_merge($this->bookingData, ['status' => 'confirmed']
            ));

        $foundBooking = $this->bookingRepository->findBy('status', 'confirmed');

        $this->assertTrue($foundBooking->is($confirmedBooking));
    }

    public function test_booking_repository_can_paginate_all_bookings(): void
    {
        Booking::factory()->count(3)->create([
            'customer_id' => $this->customer->id,
            'resource_id' => $this->resource->id,
            'slot_id' => $this->slot->id,
        ]);

        $bookings = $this->bookingRepository->all();

        $this->assertInstanceOf(LengthAwarePaginator::class, $bookings);
        $this->assertSame(3, $bookings->total());
    }

    public function test_booking_repository_can_update_booking(): void
    {
        $booking = Booking::factory()->create($this->bookingData);

        $updated = $this->bookingRepository->update(['status' => 'confirmed'], $booking->id);

        $this->assertTrue($updated);
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_booking_repository_can_delete_booking(): void
    {
        $booking = Booking::factory()->create($this->bookingData);

        $deleted = $this->bookingRepository->delete($booking->id);

        $this->assertTrue($deleted);
        $this->assertSoftDeleted('bookings', [
            'id' => $booking->id,
        ]);
        $this->expectException(ModelNotFoundException::class);

        $this->bookingRepository->find($booking->id);
    }

    public function test_booking_service_can_update_booking(): void
    {
        $booking = Booking::factory()->create($this->bookingData);
        $service = app(BookingService::class);

        $updated = $service->updateBooking(['status' => 'confirmed'], $booking->id);

        $this->assertTrue($updated);
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_booking_service_can_delete_booking(): void
    {
        $booking = Booking::factory()->create($this->bookingData);
        $service = app(BookingService::class);

        $deleted = $service->deleteBooking($booking->id);

        $this->assertTrue($deleted);
        $this->assertSoftDeleted('bookings', [
            'id' => $booking->id,
        ]);
    }

    public function test_booking_service_can_get_all_bookings(): void
    {
        Booking::factory()->count(2)->create([
            'customer_id' => $this->customer->id,
            'resource_id' => $this->resource->id,
            'slot_id' => $this->slot->id,
        ]);
        $service = app(BookingService::class);

        $bookings = $service->getAllBookings();

        $this->assertInstanceOf(LengthAwarePaginator::class, $bookings);
        $this->assertSame(2, $bookings->total());
    }

    public function test_booking_service_can_get_booking_by_id(): void
    {
        $booking = Booking::factory()->create($this->bookingData);
        $service = app(BookingService::class);

        $foundBooking = $service->getBookingById($booking->id);

        $this->assertTrue($foundBooking->is($booking));
    }

    public function test_booking_repository_gets_confirmed_bookings_for_reminder_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 09:00:00'));

        $matchingSlot = Slot::factory()->create(['date' => '2026-07-16']);
        $outsideSlot = Slot::factory()->create(['date' => '2026-07-17']);

        $matchingBooking = Booking::factory()->create(array_merge($this->bookingData, [
            'slot_id' => $matchingSlot->id,
            'status' => 'confirmed',
        ]));
        Booking::factory()->create(array_merge($this->bookingData, [
            'slot_id' => $outsideSlot->id,
            'status' => 'confirmed',
        ]));
        Booking::factory()->create(array_merge($this->bookingData, [
            'slot_id' => $matchingSlot->id,
            'status' => 'pending',
        ]));

        $bookings = $this->bookingRepository->getBookingForReminder(1);

        $this->assertCount(1, $bookings);
        $this->assertTrue($bookings->first()->is($matchingBooking));

        Carbon::setTestNow();
    }

    public function test_booking_repository_claims_only_unsent_reminders_and_marks_statuses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 09:00:00'));

        $slot = Slot::factory()->create(['date' => '2026-07-16']);
        $claimable = Booking::factory()->create(array_merge($this->bookingData, [
            'slot_id' => $slot->id,
            'status' => 'confirmed',
            'reminder_sent_at' => null,
        ]));
        Booking::factory()->create(array_merge($this->bookingData, [
            'slot_id' => $slot->id,
            'status' => 'confirmed',
            'reminder_sent_at' => Carbon::parse('2026-07-15 08:00:00'),
        ]));

        $bookings = $this->bookingRepository->claimBookingReminders(1);

        $this->assertCount(1, $bookings);
        $this->assertTrue($bookings->first()->is($claimable));
        $this->assertTrue($this->bookingRepository->markReminderAsSent($claimable));
        $this->assertNotNull($claimable->fresh()->reminder_sent_at);
        $this->assertTrue($this->bookingRepository->markReminderAsFailed($claimable));
        $this->assertNull($claimable->fresh()->reminder_sent_at);

        Carbon::setTestNow();
    }

    public function test_booking_repository_finds_and_cancels_booking_for_cancellation(): void
    {
        $booking = Booking::factory()->create(array_merge($this->bookingData, [
            'status' => 'confirmed',
        ]));

        $found = $this->bookingRepository->findForCancellation($booking->id);
        $cancelled = $this->bookingRepository->cancel($found);

        $this->assertTrue($found->relationLoaded('slot'));
        $this->assertSame('canceled', $cancelled->status);
    }

    public function test_customer_resource_and_slot_relationships_return_expected_records(): void
    {
        $booking = Booking::factory()->create($this->bookingData);
        $document = BookingDocument::factory()->create([
            'booking_id' => $booking->id,
        ]);

        $this->assertTrue($this->customer->bookings()->first()->is($booking));
        $this->assertTrue($this->customer->bookingDocuments()->first()->is($document));
        $this->assertTrue($this->resource->bookings()->first()->is($booking));
        $this->assertTrue($this->slot->bookings()->first()->is($booking));

        $this->assertInstanceOf(HasMany::class, $this->customer->bookings());
        $this->assertInstanceOf(HasManyThrough::class, $this->customer->bookingDocuments());
        $this->assertInstanceOf(HasMany::class, $this->resource->bookings());
        $this->assertInstanceOf(HasMany::class, $this->resource->slots());
        $this->assertInstanceOf(HasMany::class, $this->slot->bookings());
        $this->assertInstanceOf(BelongsTo::class, $this->slot->resource());
    }
}
