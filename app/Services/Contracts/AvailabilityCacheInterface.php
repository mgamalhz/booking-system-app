<?php

namespace App\Services\Contracts;

use App\Data\AvailabilityCriteria;
use App\Models\Resource;

interface AvailabilityCacheInterface
{
    /**
     * @param  array<int, array{slot_id: int, starts_at: string, ends_at: string}>  $slots
     * @return array<int, array{slot_id: int, starts_at: string, ends_at: string}>
     */
    public function remember(Resource $resource, AvailabilityCriteria $criteria, array $slots): array;
}
