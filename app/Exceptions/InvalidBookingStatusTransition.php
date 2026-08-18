<?php

namespace App\Exceptions;

use DomainException;

class InvalidBookingStatusTransition extends DomainException
{
    public static function for(int $bookingId, string $fromStatus, string $toStatus): self
    {
        return new self("Booking {$bookingId} cannot transition from {$fromStatus} to {$toStatus}.");
    }
}
