<?php

namespace App\Services;

use App\Events\BookingCancelled;
use App\Events\BookingCompleted;
use App\Events\BookingConfirmed;
use App\Exceptions\InvalidBookingStatusTransition;
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
                return $this->createBooking($data);
            });
    }

    public function updateBooking(array $data, int $id): bool
    {
        $this->updateExistingBooking($this->bookingRepository->find($id), $data);

        return true;
    }

    /**
     * @throws InvalidBookingStatusTransition
     */
    public function updateExistingBooking(Booking $booking, array $data): Booking
    {
        $requestedStatus = $data['status'] ?? null;
        unset($data['status']);

        if ($data !== []) {
            $this->bookingRepository->update($data, $booking->id);
        }

        if ($requestedStatus !== null) {
            $booking = $this->transitionStatus($this->bookingRepository->find($booking->id), $requestedStatus);
        }

        return $this->bookingRepository->find($booking->id);
    }

    /**
     * @throws InvalidBookingStatusTransition
     */
    public function transitionStatus(Booking $booking, string $toStatus): Booking
    {
        $fromStatus = (string) $booking->status;

        if ($fromStatus === $toStatus) {
            return $booking;
        }

        if (! $this->canTransition($fromStatus, $toStatus)) {
            throw InvalidBookingStatusTransition::for($booking->id, $fromStatus, $toStatus);
        }

        $occurredAt = now()->toISOString();

        $this->bookingRepository->update(['status' => $toStatus], $booking->id);

        $updatedBooking = $this->bookingRepository->find($booking->id);

        match ($toStatus) {
            'confirmed' => BookingConfirmed::dispatch(
                $updatedBooking->id,
                $updatedBooking->customer_id,
                $updatedBooking->slot_id,
                $updatedBooking->resource_id,
                $fromStatus,
                $toStatus,
                $occurredAt,
            ),
            'canceled' => BookingCancelled::dispatch(
                $updatedBooking->id,
                $updatedBooking->customer_id,
                $updatedBooking->slot_id,
                $updatedBooking->resource_id,
                $fromStatus,
                $toStatus,
                $occurredAt,
            ),
            'completed' => BookingCompleted::dispatch(
                $updatedBooking->id,
                $updatedBooking->customer_id,
                $updatedBooking->slot_id,
                $updatedBooking->resource_id,
                $fromStatus,
                $toStatus,
                $occurredAt,
            ),
            default => null,
        };

        return $updatedBooking;
    }

    private function canTransition(string $fromStatus, string $toStatus): bool
    {
        return in_array($toStatus, match ($fromStatus) {
            'pending' => ['confirmed', 'canceled'],
            'confirmed' => ['canceled', 'completed'],
            'canceled', 'completed' => [],
            default => [],
        }, true);
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
