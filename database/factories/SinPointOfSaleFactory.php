<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\SinBranch;
use App\Models\SinPointOfSale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SinPointOfSale>
 */
class SinPointOfSaleFactory extends Factory
{
    protected $model = SinPointOfSale::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'sin_branch_id' => fn (array $attributes) => SinBranch::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->id,
            'point_of_sale_code' => fake()->numberBetween(1, 999),
            'name' => 'Punto de venta',
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'point_of_sale_code' => 0,
            'name' => 'Punto de venta 0',
            'is_default' => true,
        ]);
    }
}
