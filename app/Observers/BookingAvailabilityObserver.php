<?php

namespace App\Observers;

use App\Models\Booking;
use App\Services\AvailabilityCacheInvalidator;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class BookingAvailabilityObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Booking $booking): void
    {
        app(AvailabilityCacheInvalidator::class)->schedule('booking_created');
    }

    public function updated(Booking $booking): void
    {
        if (! $booking->wasChanged(['status', 'slot_id', 'resource_id', 'deleted_at'])) {
            return;
        }

        app(AvailabilityCacheInvalidator::class)->schedule('booking_updated');
    }

    public function deleted(Booking $booking): void
    {
        app(AvailabilityCacheInvalidator::class)->schedule('booking_deleted');
    }

    public function restored(Booking $booking): void
    {
        app(AvailabilityCacheInvalidator::class)->schedule('booking_restored');
    }
}
