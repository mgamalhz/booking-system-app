<?php

namespace App\Listeners;

use App\Events\BookingConfirmed;
use App\Jobs\SendBookingConfirmation;
use App\Models\Booking;

class BookingConfirmationNotificationListener
{
    /**
     * Create the event listener.
     */
    public function __construct() {}

    /**
     * Handle the event.
     */
    public function handle(BookingConfirmed $event): void
    {
        $booking = Booking::query()->with('customer')->findOrFail($event->bookingId);

        SendBookingConfirmation::dispatch($booking)->afterCommit();
    }
}
