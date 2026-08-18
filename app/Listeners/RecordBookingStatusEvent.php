<?php

namespace App\Listeners;

use App\Models\BookingStatusEvent;

class RecordBookingStatusEvent
{
    public function handle(object $event): void
    {
        BookingStatusEvent::query()->create([
            'booking_id' => $event->bookingId,
            'customer_id' => $event->customerId,
            'slot_id' => $event->slotId,
            'resource_id' => $event->resourceId,
            'from_status' => $event->fromStatus,
            'to_status' => $event->toStatus,
            'event_type' => class_basename($event),
            'occurred_at' => $event->occurredAt,
        ]);
    }
}
