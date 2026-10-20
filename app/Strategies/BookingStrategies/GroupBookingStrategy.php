<?php

namespace App\Strategies\BookingStrategies;

use App\Exceptions\ApiConflictException;
use App\Models\Booking;
use App\Models\Resource;

class GroupBookingStrategy implements BookingStrategyInterface
{
    public function createBooking(array $data): Booking
    {
        $capacity = (int) Resource::query()->findOrFail($data['resource_id'])->capacity;
        $active = Booking::query()->where('slot_id', $data['slot_id'])
            ->whereIn('status', ['pending', 'confirmed']);
        if ((clone $active)->count() >= $capacity) {
            throw new ApiConflictException('Group booking is full.');
        }
        if ((clone $active)->where(fn ($query) => $query->where('type', '!=', 'group')
            ->orWhere('resource_id', '!=', $data['resource_id'])
            ->orWhere('customer_id', $data['customer_id']))->exists()) {
            throw new ApiConflictException('Slot is not available for this group booking.');
        }

        return Booking::create([
            'customer_id' => $data['customer_id'],
            'resource_id' => $data['resource_id'],
            'slot_id' => $data['slot_id'],
            'type' => 'group',
            'status' => 'pending',
            'max_participants' => $capacity,
        ]);
    }
}
