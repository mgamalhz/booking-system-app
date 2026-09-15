<?php

namespace App\Services\Contracts;

use App\Data\AvailabilityCriteria;
use App\Models\Resource;
use Closure;

interface AvailabilityCacheInterface
{
    /**
     * @param  Closure(): array<int, array{slot_id: int, starts_at: string, ends_at: string}>  $resolveSlots
     * @return array<int, array{slot_id: int, starts_at: string, ends_at: string}>
     */
    public function remember(Resource $resource, AvailabilityCriteria $criteria, Closure $resolveSlots): array;
}
