<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'product_category_id' => fn (array $attributes) => ProductCategory::factory()->create([
                'company_id' => $attributes['company_id'],
            ]),
            'measurement_unit_code' => 58,
            'internal_code' => fake()->unique()->bothify('PRD-#####'),
            'description' => fake()->words(4, true),
            'economic_activity_code' => fake()->numerify('######'),
            'siat_product_code' => fake()->unique()->numberBetween(1, 999999),
            'unit_price' => fake()->randomFloat(2, 1, 10000),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
