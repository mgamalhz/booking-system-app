<?php

namespace App\Repositories;

use App\Models\Booking;
use App\Repositories\Interfaces\BookingCancellationRepositoryInterface;
use App\Repositories\Interfaces\BookingRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class BookingRepository implements BookingCancellationRepositoryInterface, BookingRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Booking
    {
        /** @var Booking $booking */
        $booking = Booking::query()->create($data);

        return $booking;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data, int $id): bool
    {
        return $this->find($id)->update($data);
    }

    public function delete(int $id): bool
    {
        return $this->find($id)->delete();
    }

    /**
     * @return LengthAwarePaginator<int, Booking>
     */
    public function all(): LengthAwarePaginator
    {
        return Booking::query()->paginate();
    }

    public function find(int $id): Booking
    {
        /** @var Booking|null $booking */
        $booking = Booking::query()->findOrFail($id);

        return $booking;
    }

    /**
     * @throws ModelNotFoundException
     */
    public function findBy(string $columnName, mixed $value): Booking
    {
        /** @var Booking $booking */
        $booking = Booking::query()
            ->where($columnName, $value)
            ->firstOrFail();

        return $booking;
    }

    /**
     * @return Collection<int, Booking>
     */
    public function getBookingForReminder(int $daysBeforeReminder): Collection
    {
        $reminderDate = Carbon::now()->addDays($daysBeforeReminder)->toDateString();

        return Booking::query()
            ->confirmed()
            ->whereHas('slot', function ($query) use ($reminderDate) {
                $query->whereDate('date', $reminderDate);
            })
            ->with(['customer', 'slot'])
            ->get();
    }

    /**
     * @return Collection<int, Booking>
     */
    public function claimBookingReminders(int $daysBeforeReminder): Collection
    {
        $reminderDate = Carbon::now()->addDays($daysBeforeReminder)->toDateString();

        return Booking::query()
            ->confirmed()
            ->whereNull('reminder_sent_at')
            ->whereHas('slot', function ($query) use ($reminderDate) {
                $query->whereDate('date', $reminderDate);
            })
            ->with(['customer', 'slot'])
            ->get();
    }

    public function markReminderAsSent(Booking $booking): bool
    {
        return $booking->update([
            'reminder_sent_at' => Carbon::now(),
        ]);
    }

    public function findForCancellation(int $bookingId): Booking
    {
        /** @var Booking $booking */
        $booking = Booking::query()
            ->with('slot')
            ->findOrFail($bookingId);

        return $booking;
    }

    public function cancel(Booking $booking): Booking
    {
        $booking->update([
            'status' => 'canceled',
        ]);

        return $booking->refresh();
    }

    public function markReminderAsFailed(Booking $booking): bool
    {
        return $booking->update([
            'reminder_sent_at' => null,
        ]);
    }
}
