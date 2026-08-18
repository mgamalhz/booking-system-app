<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Cache;

class InvalidateBookingAvailabilityCache
{
    public function handle(object $event): void
    {
        Cache::forget("slot:{$event->slotId}:availability");
        Cache::forget("resource:{$event->resourceId}:availability");
    }
}
