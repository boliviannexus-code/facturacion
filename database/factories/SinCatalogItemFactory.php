<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\SinCatalogItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SinCatalogItem>
 */
class SinCatalogItemFactory extends Factory
{
    protected $model = SinCatalogItem::class;

    public function definition(): array
    {
        $code = fake()->numerify('###');

        return [
            'company_id' => Company::factory(),
            'catalog_key' => 'parametrica_tipo_documento_identidad',
            'item_key' => $code,
            'classifier_code' => $code,
            'description' => fake()->sentence(3),
            'is_active' => true,
            'raw_data' => [
                'codigoClasificador' => $code,
                'descripcion' => fake()->sentence(3),
            ],
            'synced_at' => now(),
        ];
    }
}
