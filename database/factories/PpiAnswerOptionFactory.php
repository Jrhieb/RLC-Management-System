<?php

namespace Database\Factories;

use App\Models\PpiAnswerOption;
use App\Models\PpiQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PpiAnswerOption>
 */
class PpiAnswerOptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ppi_question_id' => PpiQuestion::factory(),
            'code' => fake()->unique()->numerify('O###'),
            'label' => fake()->words(3, true),
            'points' => fake()->numberBetween(-5, 10),
            'position' => fake()->numberBetween(1, 5),
        ];
    }
}
