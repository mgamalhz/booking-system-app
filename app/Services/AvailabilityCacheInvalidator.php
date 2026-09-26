<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AvailabilityCacheInvalidator
{
    public function resource(int $resourceId, string $reason): void
    {
        try {
            Cache::store()->tags([AvailabilityCacheKey::resourceTag($resourceId)])->flush();

            Log::info('availability_cache.invalidated', compact('resourceId', 'reason'));
        } catch (Throwable $exception) {
            Log::warning('availability_cache.invalidation_failed', [
                'resource_id' => $resourceId,
                'reason' => $reason,
                'exception' => $exception::class,
            ]);
        }
    }

    public function schedule(string $reason): void
    {
        try {
            Cache::store()->tags([AvailabilityCacheKey::scheduleTag()])->flush();

            Log::info('availability_cache.schedule_invalidated', compact('reason'));
        } catch (Throwable $exception) {
            Log::warning('availability_cache.schedule_invalidation_failed', [
                'reason' => $reason,
                'exception' => $exception::class,
            ]);
        }
    }
}
