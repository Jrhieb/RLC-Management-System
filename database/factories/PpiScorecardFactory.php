<?php

namespace Database\Factories;

use App\Models\PpiScorecard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PpiScorecard>
 */
class PpiScorecardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Fictional PPI Scorecard',
            'version' => fake()->unique()->numerify('test-####'),
            'country' => 'PH',
            'source_reference' => 'Fictional test fixture only - not an official scorecard',
            'effective_from' => now()->toDateString(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
