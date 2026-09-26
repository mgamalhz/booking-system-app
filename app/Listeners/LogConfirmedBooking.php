<?php

namespace App\Listeners;

use App\Events\BookingConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class LogConfirmedBooking implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct() {}

    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        if ($event instanceof BookingConfirmed && $event->booking !== null) {
            Log::debug('Booking confirmed: '.$event->bookingId, []);

            return;
        }

        Log::info('Booking status changed', [
            'booking_id' => $event->bookingId,
            'customer_id' => $event->customerId,
            'slot_id' => $event->slotId,
            'resource_id' => $event->resourceId,
            'from_status' => $event->fromStatus,
            'to_status' => $event->toStatus,
            'occurred_at' => $event->occurredAt,
        ]);
    }
}
