<?php

namespace App\Factories;

use App\Models\Booking;
use App\Models\Slot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RecurringBookingFactory implements BookingFactoryInterface
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws \Throwable
     */
    public function create(array $data): Booking
    {
        if (! isset($data['recurrence_rule'], $data['end_date'])) {
            throw new \Exception('Recurrence rule and end date are required.');
        }

        return DB::transaction(function () use ($data) {
            $dates = $this->generateDates(
                Carbon::parse($data['start_date']),
                Carbon::parse($data['end_date']),
                $data['recurrence_rule']
            );

            $firstBooking = null;

            foreach ($dates as $date) {
                $slot = Slot::whereDate('date', $date)->where('start_time', $data['start_time'])->first();

                $alreadyBooked = Booking::where('slot_id', $slot->id)
                    ->whereIn('status', ['confirmed', 'pending'])
                    ->exists();

                if ($alreadyBooked) {
                    throw new \Exception("Slot already booked for date {$date->toDateString()}.");
                }

                $booking = Booking::create([
                    'customer_id' => $data['customer_id'],
                    'slot_id' => $slot->id,
                    'type' => 'recurring',
                    'status' => 'confirmed',
                    'recurrence_rule' => $data['recurrence_rule'],
                    'end_date' => $data['end_date'],
                ]);

                $firstBooking ??= $booking;
            }

            return $firstBooking;
        });
    }

    /**
     * @return array<int, Carbon>
     */
    private function generateDates(Carbon $startDate, Carbon $endDate, string $rule): array
    {
        $dates = [];

        $current = $startDate->copy();

        while ($current->lte($endDate)) {
            $dates[] = $current->copy();

            match ($rule) {
                'weekly' => $current->addWeek(),
                'biweekly' => $current->addWeeks(2),
                default => throw new \Exception('Invalid recurrence rule.'),
            };
        }

        return $dates;
    }
}
