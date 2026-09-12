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

    public function forResource(
        Resource $resource,
        string $startDate,
        string $endDate,
        string $timezone,
        array $filters = [],
    ): array {
        $criteria = new AvailabilityCriteria($startDate, $endDate, $timezone, $filters);

        $slots = $this
            ->availabilityRepository->availableForResource(
                $resource,
                $startDate,
                $endDate,
                $timezone,
                $filters);

        return $this->cache->remember(
            $resource,
            $criteria,
            $slots
        );
    }
}
