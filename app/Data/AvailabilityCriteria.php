<?php

namespace App\Data;

final readonly class AvailabilityCriteria
{
    public function __construct(
        public string $startDate,
        public string $endDate,
        public string $timezone,
        public array $filters = [],
    ) {}
}
