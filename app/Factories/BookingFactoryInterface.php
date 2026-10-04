<?php

namespace App\Factories;

use App\Models\Booking;

interface BookingFactoryInterface
{
    public function create(array $data): Booking;
}
