<?php

namespace App\Repositories;

use App\Models\Resource;
use App\Models\Slot;
use App\Repositories\Interfaces\SlotAvailabilityRepositoryInterface;
use Carbon\CarbonImmutable;

class SlotAvailabilityRepository implements SlotAvailabilityRepositoryInterface
{
    /** @return array<int, array{slot_id: int, starts_at: string, ends_at: string}> */
    public function availableForResource(
        Resource $resource,
        string $startDate,
        string $endDate,
        string $timezone,
        array $filters = [],
    ): array {
        $slots = Slot::query()
            ->where('status', 'active')
            ->whereBetween('date', [$startDate, $endDate])
            ->when(isset($filters['starts_after']), fn ($query) => $query->whereTime('start_time', '>=', $filters['starts_after']))
            ->when(isset($filters['ends_before']), fn ($query) => $query->whereTime('end_time', '<=', $filters['ends_before']))
            ->whereDoesntHave('bookings', fn ($query) => $query
                ->where('resource_id', $resource->id)
                ->whereIn('status', ['pending', 'confirmed']))
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $sourceTimezone = (string) config('app.timezone', 'UTC');

        return $slots->map(fn (Slot $slot): array => [
            'slot_id' => $slot->id,
            'starts_at' => CarbonImmutable::parse($slot->date->toDateString().' '.$slot->start_time, $sourceTimezone)
                ->setTimezone($timezone)->toIso8601String(),
            'ends_at' => CarbonImmutable::parse($slot->date->toDateString().' '.$slot->end_time, $sourceTimezone)
                ->setTimezone($timezone)->toIso8601String(),
        ])->all();
    }
}
