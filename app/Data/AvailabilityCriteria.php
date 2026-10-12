<?php

namespace App\Data;

final readonly class AvailabilityCriteria
{
    /**
     * @param  array{starts_after?: string, ends_before?: string}  $filters
     */
    public function __construct(
        public string $startDate,
        public string $endDate,
        public string $timezone,
        public array $filters = [],
    ) {}
}
