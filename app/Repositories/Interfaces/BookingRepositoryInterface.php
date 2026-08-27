<?php

namespace App\Repositories\Interfaces;

use App\Models\Booking;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface BookingRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Booking;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data, int $id): bool;

    public function delete(int $id): bool;

    /**
     * @return LengthAwarePaginator<int, Booking>
     */
    public function all(): LengthAwarePaginator;

    public function find(int $id): Booking;

    public function findBy(string $columnName, mixed $value): Booking;

    /**
     * @return Collection<int, Booking>
     */
    public function getBookingForReminder(int $daysBeforeReminder): Collection;

    /**
     * @return Collection<int, Booking>
     */
    public function claimBookingReminders(int $daysBeforeReminder): Collection;

    public function markReminderAsSent(Booking $booking): bool;

    public function markReminderAsFailed(Booking $booking): bool;
}
