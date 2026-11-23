<?php

namespace Database\Factories;

use App\Enums\PpiApplicationStatus;
use App\Models\PpiHouseholdApplication;
use App\Models\PpiScorecard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PpiHouseholdApplication>
 */
class PpiHouseholdApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => fake()->unique()->bothify('PPI-2026-########'),
            'submission_key' => fake()->uuid(),
            'household_key' => hash('sha256', fake()->unique()->uuid()),
            'ppi_scorecard_id' => PpiScorecard::factory(),
            'child_first_name' => fake()->firstName(),
            'child_middle_name' => fake()->optional()->firstName(),
            'child_last_name' => fake()->lastName(),
            'child_suffix' => null,
            'child_sex' => fake()->randomElement(['male', 'female']),
            'child_date_of_birth' => fake()->dateTimeBetween('-17 years', '-1 year')->format('Y-m-d'),
            'guardian_name' => fake()->name(),
            'guardian_relationship' => fake()->randomElement(['Parent', 'Guardian', 'Other']),
            'contact_number' => fake()->unique()->numerify('09#########'),
            'email' => fake()->safeEmail(),
            'barangay' => fake()->city(),
            'city_municipality' => fake()->city(),
            'complete_address' => fake()->address(),
            'ppi_score' => fake()->numberBetween(0, 100),
            'status' => PpiApplicationStatus::Submitted,
            'privacy_acknowledged_at' => now(),
            'submitted_at' => now(),
            'scored_at' => now(),
        ];
    }

    public function unscored(): static
    {
        return $this->state(fn (): array => [
            'ppi_score' => null,
            'scored_at' => null,
        ]);
    }
}
