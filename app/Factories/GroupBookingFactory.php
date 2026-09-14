<?php

namespace App\Factories;

use App\Models\Booking;
use App\Models\Slot;
use Exception;

class GroupBookingFactory implements BookingFactoryInterface
{
    public function create(array|string $data): Booking
    {
        if (! isset($data['max_participants'])) {
            throw new Exception('Max participants is required for group booking.');
        }

        $slotId = $data['slot_id'] ?? null;
        if (! is_int($slotId)) {
            throw new Exception('A valid slot id is required for group booking.');
        }

        $slot = Slot::query()->findOrFail($slotId);

        $status = $this->determineGroupBookingStatus($slot, $data);

        $booking = Booking::create([
            'customer_id' => $data['customer_id'],
            'resource_id' => $data['resource_id'],
            'slot_id' => $data['slot_id'],
            'type' => 'group',
            'status' => $status,
            'max_participants' => $data['max_participants'],
        ]);

        return $booking;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Exception
     */
    private function determineGroupBookingStatus(Slot $slot, array $data): string
    {
        $currentParticipants = Booking::where('slot_id', $slot->id)
            ->where('type', 'group')
            ->whereIn('status', ['pending', 'confirmed'])
            ->count();

        if ($currentParticipants >= $data['max_participants']) {
            throw new Exception('Group booking is full.');
        }

        return $currentParticipants + 1 >= ($data['min_participants'] ?? 1)
            ? 'confirmed'
            : 'pending';
    }
}
