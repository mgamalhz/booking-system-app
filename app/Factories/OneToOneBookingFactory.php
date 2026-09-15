<?php

namespace App\Factories;

use App\Models\Booking;
use Illuminate\Support\Arr;

class OneToOneBookingFactory implements BookingFactoryInterface
{
    /**
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

    private function isSlotAvailability($slotId): bool
    {
        return Booking::query()
            ->where('slot_id', $slotId)
            ->whereIn('status', ['pending', 'confirmed'])
            ->doesntExist();
    }

    /**
     * @throws \Exception
     */
    private function checkSlotAndCustomerCount($customerId, $slotId): void
    {
        if (count(Arr::wrap($customerId)) > 1) {
            throw new \Exception('More one Customer in this type is not available');
        }

        if (count(Arr::wrap($slotId)) > 1) {
            throw new \Exception('More one Slot in this type is not available');
        }
    }
}
