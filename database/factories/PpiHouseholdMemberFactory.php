<?php

namespace Database\Factories;

use App\Models\PpiHouseholdApplication;
use App\Models\PpiHouseholdMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PpiHouseholdMember>
 */
class PpiHouseholdMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ppi_household_application_id' => PpiHouseholdApplication::factory(),
            'name' => fake()->name(),
            'relationship' => fake()->randomElement(['Mother', 'Father', 'Child', 'Grandparent']),
            'monthly_income' => fake()->randomFloat(2, 0, 20000),
            'position' => 1,
        ];
    }
}
