<?php

namespace App\Observers;

use App\Models\Booking;
use App\Services\AvailabilityCacheInvalidator;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class BookingAvailabilityObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Booking $booking): void
    {
        app(AvailabilityCacheInvalidator::class)->resource($booking->resource_id, 'booking_created');
    }

    public function updated(Booking $booking): void
    {
        if (! $booking->wasChanged(['status', 'slot_id', 'resource_id', 'deleted_at'])) {
            return;
        }

        $resourceIds = array_unique([(int) $booking->resource_id, (int) $booking->getOriginal('resource_id')]);
        foreach ($resourceIds as $resourceId) {
            app(AvailabilityCacheInvalidator::class)->resource($resourceId, 'booking_updated');
        }
    }

    public function deleted(Booking $booking): void
    {
        app(AvailabilityCacheInvalidator::class)->resource($booking->resource_id, 'booking_deleted');
    }

    public function restored(Booking $booking): void
    {
        app(AvailabilityCacheInvalidator::class)->resource($booking->resource_id, 'booking_restored');
    }
}
