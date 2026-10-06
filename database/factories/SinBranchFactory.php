<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\SinBranch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SinBranch>
 */
class SinBranchFactory extends Factory
{
    protected $model = SinBranch::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'branch_code' => fake()->numberBetween(1, 999),
            'name' => fake()->city(),
            'is_main' => false,
            'is_active' => true,
        ];
    }

    public function main(): static
    {
        return $this->state(fn (array $attributes): array => [
            'branch_code' => 0,
            'name' => 'Casa matriz',
            'is_main' => true,
        ]);
    }
}
