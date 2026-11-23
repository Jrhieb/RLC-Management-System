<?php

namespace Database\Factories;

use App\Models\PpiQuestion;
use App\Models\PpiScorecard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PpiQuestion>
 */
class PpiQuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ppi_scorecard_id' => PpiScorecard::factory(),
            'code' => fake()->unique()->numerify('Q###'),
            'prompt' => fake()->sentence(),
            'position' => fake()->numberBetween(1, 10),
        ];
    }
}
