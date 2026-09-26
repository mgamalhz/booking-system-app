<?php

namespace App\Observers;

use App\Models\Resource;
use App\Services\AvailabilityCacheInvalidator;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ResourceAvailabilityObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(Resource $resource): void
    {
        if ($resource->wasChanged('status')) {
            app(AvailabilityCacheInvalidator::class)->resource($resource->id, 'resource_status_updated');
        }
    }

    public function deleted(Resource $resource): void
    {
        app(AvailabilityCacheInvalidator::class)->resource($resource->id, 'resource_deleted');
    }
}
