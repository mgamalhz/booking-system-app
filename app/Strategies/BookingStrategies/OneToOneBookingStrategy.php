<?php

namespace App\Strategies\BookingStrategies;

use App\Models\Booking;

class OneToOneBookingStrategy implements BookingStrategyInterface
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws \Exception
     */
    public function createBooking(array $data): Booking
    {
        $data = array_merge([
            'type' => 'one-on-one',
            'status' => 'pending',
        ], $data);

        if (! $this->isSlotAvailability($data['slot_id'])) {
            throw new \Exception('Slot is not available');
        }

        return Booking::create($data);

    }

    private function isSlotAvailability(mixed $slotId): bool
    {
        return Booking::query()
            ->where('slot_id', $slotId)
            ->whereIn('status', ['pending', 'confirmed'])
            ->doesntExist();
    }
}
