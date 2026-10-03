<?php

namespace App\Repositories\Interfaces;

use App\Models\Resource;

interface SlotAvailabilityRepositoryInterface
{
    /** @return array<int, array{slot_id: int, starts_at: string, ends_at: string}> */
    public function availableForResource(
        Resource $resource,
        string $startDate,
        string $endDate,
        string $timezone,
        array $filters = [],
    ): array;
}
