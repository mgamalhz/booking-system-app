<?php

namespace App\Services;

final class AvailabilityCacheKey
{
    public static function make(
        int $resourceId,
        string $startDate,
        string $endDate,
        string $timezone,
        array $filters,
        string $version,
        int $scheduleVersion,
    ): string {
        ksort($filters);

        $dimensions = json_encode([
            'resource' => $resourceId,
            'start' => $startDate,
            'end' => $endDate,
            'timezone' => $timezone,
            'filters' => $filters,
            'version' => $version,
            'schedule_version' => $scheduleVersion,
        ], JSON_THROW_ON_ERROR);

        return 'availability:'.$version.':resource:'.$resourceId.':'.hash('sha256', $dimensions);
    }

    public static function resourceTag(int $resourceId): string
    {
        return 'availability:resource:'.$resourceId;
    }

    public static function scheduleVersionKey(): string
    {
        return 'availability:schedule-version';
    }
}
