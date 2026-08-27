<?php

namespace App\Strategies\BookingStrategies;

use App\Models\Booking;
use App\Models\Slot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RecurringBookingStrategy implements BookingStrategyInterface
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws \Exception
     * @throws \Throwable
     */
    public function createBooking(array $data): Booking
    {

        if (! isset($data['recurrence_rule'], $data['start_date'], $data['end_date'])) {
            throw new \Exception('Recurrence rule and dates are required.');
        }

        return DB::transaction(function () use ($data) {
            $dates = $this->generateDates(
                Carbon::parse($data['start_date']),
                Carbon::parse($data['end_date']),
                $data['recurrence_rule']
            );

            if (! isset($data['start_time'], $data['end_time'], $data['customer_id'], $data['resource_id'])) {
                throw new \Exception('Times, customer, and resource are required for recurring booking.');
            }

            $firstBooking = null;

            if (empty($dates)) {
                throw new \Exception('No valid date range found for the given recurrence rule.');
            }

            foreach ($dates as $date) {
                $slot = Slot::firstOrCreate([
                    'date' => $date,
                    'start_time' => $data['start_time'],
                    'end_time' => $data['end_time'],
                    'status' => 'active',
                ]);

                $alreadyBooked = Booking::where('slot_id', $slot->id)
                    ->whereIn('status', ['confirmed', 'pending'])
                    ->exists();

                if ($alreadyBooked) {
                    throw new \Exception("Slot already booked for date {$date->toDateString()}.");
                }

                $booking = Booking::create([
                    'customer_id' => $data['customer_id'],
                    'resource_id' => $data['resource_id'],
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
    public function generateDates(Carbon $startDate, Carbon $endDate, string $rule): array
    {
        $dates = [];

        $current = $startDate->copy();

        if ($endDate->lessThanOrEqualTo($startDate)) {
            throw new \Exception('End date must be after start date for recurring booking.');
        }

        while ($current->lte($endDate)) {
            $dates[] = $current->copy();

            match ($rule) {
                'weekly' => $current->addWeek(),
                'biweekly' => $current->addWeeks(2),
                'monthly' => $current->addMonth(),
                default => throw new \Exception('Invalid recurrence rule.'),
            };
        }

        return $dates;
    }
}
