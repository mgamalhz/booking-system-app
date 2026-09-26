<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => 'pending',
            'customer_id' => Customer::factory(1)->create()->pluck('id')->first(),
            'resource_id' => Resource::factory(1)->create()->pluck('id')->first(),
            'slot_id' => Slot::factory(1)->create()->pluck('id')->first(),
        ];
    }
}
