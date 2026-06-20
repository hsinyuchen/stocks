<?php

namespace Database\Factories;

use App\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Instrument>
 */
class InstrumentFactory extends Factory
{
    protected static int $sequence = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $symbols = ['2330.TW', '2454.TW', 'AAPL', 'NVDA', 'QQQ'];
        $sequence = self::$sequence++;
        $symbol = $symbols[$sequence % count($symbols)].str_pad((string) (int) floor($sequence / count($symbols)) + 1, 3, '0', STR_PAD_LEFT);

        return [
            'symbol' => $symbol,
            'name' => fake()->company(),
            'market' => 'US',
            'asset_type' => 'stock',
            'currency' => 'USD',
            'exchange' => 'NASDAQ',
        ];
    }
}
