<?php

namespace App\Strategies\BookingStrategies;

use App\Models\Booking;

interface BookingStrategyInterface
{
    public function createBooking(array $data): Booking;
}
