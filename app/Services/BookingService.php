<?php

namespace App\Services;

use App\Jobs\SendBookingConfirmation;
use App\Models\Booking;
use App\Repositories\Interfaces\BookingRepositoryInterface;
use App\Strategies\BookingStrategies\BookingStrategyResolver;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class BookingService
{
    public function __construct(private BookingRepositoryInterface $bookingRepository) {}

    public function createBooking(array $data): Booking
    {
        $type = $data['type'] ?? 'one-on-one';

        return BookingStrategyResolver::resolve($type)->createBooking($data);
    }

    /**
     * @throws LockTimeoutException
     */
    public function createBookingForCustomer(array $data, int $customerId): Booking
    {
        $waitSeconds = (int) config('booking.lock.wait_seconds');
        $ttl = (int) config('booking.lock.ttl_seconds');
        $data = array_merge($data, ['customer_id' => $customerId]);

        return Cache::lock("slot:{$data['slot_id']}:book", $ttl)
            ->block($waitSeconds, function () use ($data) {
                $booking = $this->createBooking($data);
                SendBookingConfirmation::dispatchIf(
                    $booking->status === 'confirmed',
                    $booking,
                )->afterCommit();

                return $booking;
            });
    }

    public function updateBooking(array $data, int $id): bool
    {
        return $this->bookingRepository->update($data, $id);
    }

    public function updateExistingBooking(Booking $booking, array $data): Booking
    {
        // Route model binding already loaded this row. Updating it directly avoids
        // re-reading the booking and all three globally eager-loaded relations.
        $booking->update($data);
        $updatedBooking = $booking->refresh()->loadMissing(['slot', 'resource', 'customer']);

        SendBookingConfirmation::dispatchIf(
            $updatedBooking->status === 'confirmed',
            $updatedBooking,
        )->afterCommit();

        return $updatedBooking;
    }

    public function deleteBooking(int $id): bool
    {
        return $this->bookingRepository->delete($id);
    }

    public function getAllBookings(): LengthAwarePaginator
    {
        return $this->bookingRepository->all();
    }

    public function getBookingById(int $id): Booking
    {
        return $this->bookingRepository->find($id);
    }

    public function getBookingForReminder(int $daysBeforeReminder): Collection
    {
        return $this->bookingRepository->getBookingForReminder($daysBeforeReminder);
    }

    public function claimBookingReminders(int $daysBeforeReminder): Collection
    {
        return $this->bookingRepository->claimBookingReminders($daysBeforeReminder);
    }

    public function markReminderAsSent(Booking $booking): bool
    {
        return $this->bookingRepository->markReminderAsSent($booking);
    }

    public function markReminderAsFailed(Booking $booking): bool
    {
        return $this->bookingRepository->markReminderAsFailed($booking);
    }
}
