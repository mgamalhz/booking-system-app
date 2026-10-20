<?php

namespace Database\Factories;

use App\Models\Slot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Slot>
 */
class SlotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::createFromTime(
            $this->faker->numberBetween(8, 18),
            0
        );

        $durationMinutes = $this->faker->numberBetween(30, 90);
        $end = (clone $start)->addMinutes($durationMinutes);

        return [
            'start_time' => $start->format('H:i:s'),
            'end_time' => $end->format('H:i:s'),
            'date' => Carbon::tomorrow()->toDateString(),
            'status' => 'active',
        ];
    }
}
