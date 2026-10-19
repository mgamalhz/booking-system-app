<?php

namespace App\Services;

use App\Events\BookingCancelled;
use App\Events\BookingCompleted;
use App\Events\BookingConfirmed;
use App\Exceptions\ApiConflictException;
use App\Exceptions\InvalidBookingStatusTransition;
use App\Models\Booking;
use App\Models\Resource;
use App\Models\Slot;
use App\Repositories\Interfaces\BookingRepositoryInterface;
use App\Strategies\BookingStrategies\BookingStrategyResolver;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
                // The row lock survives until the outer idempotency transaction commits.
                // Cache lock release alone is earlier than that commit.
                $booking = DB::transaction(function () use ($data) {
                    $slot = Slot::query()->whereKey($data['slot_id'])->lockForUpdate()->firstOrFail();
                    $resource = Resource::query()->findOrFail($data['resource_id']);
                    if ($slot->status !== 'active' || $resource->status !== 'active'
                        || Carbon::parse($slot->date->toDateString().' '.$slot->start_time)->isPast()) {
                        throw new ApiConflictException('The selected inventory is not available.');
                    }
                    if (($data['type'] ?? 'one-on-one') === 'recurring'
                        && ($slot->date->toDateString() !== $data['start_date']
                            || substr($slot->start_time, 0, 5) !== $data['start_time']
                            || substr($slot->end_time, 0, 5) !== $data['end_time'])) {
                        throw new ApiConflictException('Recurrence must start at the selected slot.');
                    }

                    return $this->createBooking($data);
                });

                return $booking;
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
        return DB::transaction(function () use ($booking, $data) {
            $locked = Booking::query()->without(['slot', 'resource', 'customer'])->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            return $this->applyUpdate($locked, $data);
        });
    }

    private function applyUpdate(Booking $booking, array $data): Booking
    {
        $fromStatus = (string) $booking->status;
        $toStatus = array_key_exists('status', $data) ? (string) $data['status'] : $fromStatus;
        $statusChanged = $fromStatus !== $toStatus;
        $occurredAt = now()->toISOString();

        if ($statusChanged && ! $this->canTransition($fromStatus, $toStatus)) {
            throw InvalidBookingStatusTransition::for($booking->id, $fromStatus, $toStatus);
        }

        $booking->update($data);
        $updatedBooking = $booking->loadMissing(['slot', 'resource', 'customer']);

        if (! $statusChanged) {
            return $updatedBooking;
        }

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

    /**
     * @throws InvalidBookingStatusTransition
     */
    public function transitionStatus(Booking $booking, string $toStatus): Booking
    {
        return $this->updateExistingBooking($booking, ['status' => $toStatus]);
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

    public function getBookingIndex(int $perPage, ?int $customerId = null): LengthAwarePaginator
    {
        return $this->bookingRepository->indexPage($perPage, $customerId);
    }

    public function getBottleneckBookingIndex(int $perPage, ?int $customerId = null): LengthAwarePaginator
    {
        return $this->bookingRepository->bottleneckIndexPage($perPage, $customerId);
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
