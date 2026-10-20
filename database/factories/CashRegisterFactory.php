<?php

namespace Database\Factories;

use App\Models\CashRegister;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashRegister>
 */
class CashRegisterFactory extends Factory
{
    protected $model = CashRegister::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => function (array $attributes): int {
                return User::factory()->create([
                    'company_id' => $attributes['company_id'],
                ])->id;
            },
            'opening_amount' => fake()->randomFloat(2, 0, 1000),
            'closing_amount' => null,
            'opening_notes' => fake()->optional()->sentence(),
            'closing_notes' => null,
            'opened_at' => now(),
            'closed_at' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'closing_amount' => fake()->randomFloat(2, 0, 2000),
            'closing_notes' => fake()->optional()->sentence(),
            'closed_at' => now(),
        ]);
    }
}
