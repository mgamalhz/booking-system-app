<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Cache;
use Throwable;

class InvalidateBookingAvailabilityCache
{
    public function handle(object $event): void
    {
        try {
            Cache::forget("slot:{$event->slotId}:availability");
            Cache::forget("resource:{$event->resourceId}:availability");
        } catch (Throwable) {
            // Cache invalidation should never fail the booking status transition.
        }
    }
}
