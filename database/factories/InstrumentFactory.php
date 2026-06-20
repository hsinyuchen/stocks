<?php

namespace Database\Factories;

use App\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Instrument>
 */
class InstrumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'symbol' => fake()->unique()->randomElement(['2330.TW', '2454.TW', 'AAPL', 'NVDA', 'QQQ']).fake()->unique()->numberBetween(1, 999),
            'name' => fake()->company(),
            'market' => 'US',
            'asset_type' => 'stock',
            'currency' => 'USD',
            'exchange' => 'NASDAQ',
        ];
    }
}
