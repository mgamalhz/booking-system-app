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
        return $this->forResourceId($resource->id, $startDate, $endDate, $timezone, $filters);
    }

    public function forResourceId(
        int $resourceId,
        string $startDate,
        string $endDate,
        string $timezone,
        array $filters = [],
    ): array {
        $criteria = new AvailabilityCriteria($startDate, $endDate, $timezone, $filters);

        return $this->cache->remember($resourceId, $criteria,
            function () use ($resourceId, $startDate, $endDate, $timezone, $filters): array {
                $resource = Resource::query()
                    ->whereKey($resourceId)
                    ->where('status', 'active')
                    ->firstOrFail();

                return $this->availabilityRepository->availableForResource(
                    $resource,
                    $startDate,
                    $endDate,
                    $timezone,
                    $filters
                );
            }
        );
    }
}
