<?php

namespace App\Strategies\BookingStrategies;

use App\Models\Booking;

interface BookingStrategyInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createBooking(array $data): Booking;
}
