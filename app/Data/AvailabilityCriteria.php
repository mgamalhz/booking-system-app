<?php

namespace App\Data;

final readonly class AvailabilityCriteria
{
    /**
     * @param  array{starts_after?: string, ends_before?: string, type?: 'one-on-one'|'group'}  $filters
     */
    public function __construct(
        public string $startDate,
        public string $endDate,
        public string $timezone,
        public array $filters = [],
    ) {}
}
