<?php

namespace Database\Factories;

use App\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<resource>
 */
class ResourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'type' => $this->faker->randomElement(['room', 'table', 'chair', 'other']),
            'capacity' => $this->faker->numberBetween(1, 10),
            'price' => $this->faker->numberBetween(100, 1000),
            'status' => 'active',
            'image' => $this->faker->imageUrl(),

        ];
    }
}
