<?php

namespace App\Services;

use App\Data\AvailabilityCriteria;
use App\Models\Resource;
use App\Repositories\Interfaces\SlotAvailabilityRepositoryInterface;
use App\Services\Contracts\AvailabilityCacheInterface;

class SlotAvailabilityService
{
    public function __construct(
        private readonly SlotAvailabilityRepositoryInterface $availabilityRepository,
        private readonly AvailabilityCacheInterface $cache,
    ) {}

    /**
     * @param  array{starts_after?: string, ends_before?: string}  $filters
     * @return array<int, array{slot_id: int, starts_at: string, ends_at: string}>
     */
    public function forResource(
        Resource $resource,
        string $startDate,
        string $endDate,
        string $timezone,
        array $filters = [],
    ): array {
        abort_unless($resource->status === 'active', 404);

        $criteria = new AvailabilityCriteria($startDate, $endDate, $timezone, $filters);

        return $this->cache->remember(
            $resource,
            $criteria,
            fn (): array => $this->availabilityRepository->availableForResource(
                $resource,
                $startDate,
                $endDate,
                $timezone,
                $filters
            )
        );
    }
}
