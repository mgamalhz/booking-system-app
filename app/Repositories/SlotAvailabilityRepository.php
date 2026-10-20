<?php

namespace App\Repositories;

use App\Models\Resource;
use App\Models\Slot;
use App\Repositories\Interfaces\SlotAvailabilityRepositoryInterface;
use Carbon\CarbonImmutable;

class SlotAvailabilityRepository implements SlotAvailabilityRepositoryInterface
{
    /**
     * @param  array{starts_after?: string, ends_before?: string}  $filters
     * @return list<array{slot_id: int, starts_at: string, ends_at: string}>
     */
    public function availableForResource(
        Resource $resource,
        string $startDate,
        string $endDate,
        string $timezone,
        array $filters = [],
    ): array {
        $slots = Slot::query()
            ->where('status', 'active')
            ->where('date', '>=', $startDate)
            ->where('date', '<', CarbonImmutable::parse($endDate)->addDay()->toDateString())
            ->when(isset($filters['starts_after']), fn ($query) => $query->whereTime('start_time', '>=', $filters['starts_after']))
            ->when(isset($filters['ends_before']), fn ($query) => $query->whereTime('end_time', '<=', $filters['ends_before']))
            ->when(($filters['type'] ?? 'one-on-one') === 'group',
                fn ($query) => $query
                    ->whereDoesntHave('bookings', fn ($bookings) => $bookings
                        ->whereIn('status', ['pending', 'confirmed'])
                        ->where(fn ($occupied) => $occupied->where('type', '!=', 'group')
                            ->orWhere('resource_id', '!=', $resource->id)))
                    ->whereHas('bookings', fn ($bookings) => $bookings->whereIn('status', ['pending', 'confirmed']), '<', (int) $resource->capacity),
                fn ($query) => $query->whereDoesntHave('bookings', fn ($bookings) => $bookings
                    ->whereIn('status', ['pending', 'confirmed'])))
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
