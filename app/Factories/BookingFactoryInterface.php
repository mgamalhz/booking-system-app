<?php

namespace App\Factories;

use App\Models\Booking;

interface BookingFactoryInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Booking;
}
