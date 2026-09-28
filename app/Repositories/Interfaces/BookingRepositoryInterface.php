<?php

namespace App\Repositories\Interfaces;

use App\Models\Booking;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface BookingRepositoryInterface
{
    public function create(array $data): Booking;

    public function update(array $data, int $id): bool;

    public function delete(int $id): bool;

    public function all(): LengthAwarePaginator;

    public function indexPage(int $perPage): LengthAwarePaginator;

    public function bottleneckIndexPage(int $perPage): LengthAwarePaginator;

    public function find(int $id): Booking;

    public function findBy(string $columnName, $value): Booking;

    public function getBookingForReminder(int $daysBeforeReminder): Collection;

    public function claimBookingReminders(int $daysBeforeReminder): Collection;

    public function markReminderAsSent(Booking $booking): bool;

    public function markReminderAsFailed(Booking $booking): bool;
}
