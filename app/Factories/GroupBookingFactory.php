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

        $slot = Slot::findOrFail($data['slot_id']);

        $status = $this->determineGroupBookingStatus($slot, $data);

        $booking = Booking::create([
            'customer_id' => $data['customer_id'],
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
