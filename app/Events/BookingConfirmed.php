<?php

namespace App\Events;

use App\Models\Booking;
use InvalidArgumentException;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class BookingConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public ?Booking $booking = null;

    public int $bookingId;

    public int $customerId;

    public int $slotId;

    public int $resourceId;

    public string $fromStatus;

    public string $toStatus;

    public string $occurredAt;

    public function __construct(
        Booking|int $booking,
        ?int $customerId = null,
        ?int $slotId = null,
        ?int $resourceId = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $occurredAt = null,
    ) {
        if ($booking instanceof Booking) {
            $this->booking = $booking;
            $this->bookingId = (int) $booking->id;
            $this->customerId = (int) $booking->customer_id;
            $this->slotId = (int) $booking->slot_id;
            $this->resourceId = (int) $booking->resource_id;
            $this->fromStatus = $fromStatus ?? 'pending';
            $this->toStatus = $toStatus ?? 'confirmed';
            $this->occurredAt = $occurredAt ?? now()->toISOString();

            return;
        }

        if ($customerId === null || $slotId === null || $resourceId === null || $fromStatus === null || $toStatus === null || $occurredAt === null) {
            throw new InvalidArgumentException('BookingConfirmed requires the full status transition payload.');
        }

        $this->bookingId = $booking;
        $this->customerId = $customerId;
        $this->slotId = $slotId;
        $this->resourceId = $resourceId;
        $this->fromStatus = $fromStatus;
        $this->toStatus = $toStatus;
        $this->occurredAt = $occurredAt;
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('bookings.'.$this->bookingId)];
    }
}
