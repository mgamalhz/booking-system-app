<?php

namespace App\Factories;

use App\Models\Booking;

class OneToOneBookingFactory implements BookingFactoryInterface
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws \Exception
     */
    public function create(array $data): Booking
    {
        if (! $this->isSlotAvailability($data['slot_id'])) {
            throw new \Exception('Slot is not available');
        }

        $this->checkSlotAndCustomerCount($data['customer_id'], $data['slot_id']);

        return Booking::create($data);

    }

    private function isSlotAvailability(mixed $slotId): bool
    {
        return Booking::query()
            ->where('slot_id', $slotId)
            ->whereIn('status', ['pending', 'confirmed'])
            ->doesntExist();
    }

    /**
     * @throws \Exception
     */
    private function checkSlotAndCustomerCount(mixed $customerId, mixed $slotId): void
    {
        if (is_countable($customerId) && count($customerId) > 1) {
            throw new \Exception('More one Customer in this type is not available');
        }

        if (is_countable($slotId) && count($slotId) > 1) {
            throw new \Exception('More one Slot in this type is not available');
        }
    }
}
