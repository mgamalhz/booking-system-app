<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BookingCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $bookingId,
        public int $customerId,
        public int $slotId,
        public int $resourceId,
        public string $fromStatus,
        public string $toStatus,
        public string $occurredAt,
    ) {}
}
