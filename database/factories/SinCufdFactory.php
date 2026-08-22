<?php

namespace Database\Factories;

use App\Enums\SiatEnvironment;
use App\Enums\SiatModality;
use App\Models\Company;
use App\Models\SinBranch;
use App\Models\SinCufd;
use App\Models\SinCuis;
use App\Models\SinPointOfSale;
use App\Services\Siat\SiatWsdlRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SinCufd>
 */
class SinCufdFactory extends Factory
{
    protected $model = SinCufd::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'sin_branch_id' => fn (array $attributes) => SinBranch::factory()->create(['company_id' => $attributes['company_id']])->id,
            'sin_point_of_sale_id' => fn (array $attributes) => SinPointOfSale::factory()->create([
                'company_id' => $attributes['company_id'],
                'sin_branch_id' => $attributes['sin_branch_id'],
            ])->id,
            'sin_cuis_id' => fn (array $attributes) => SinCuis::factory()->create([
                'company_id' => $attributes['company_id'],
                'sin_branch_id' => $attributes['sin_branch_id'],
                'sin_point_of_sale_id' => $attributes['sin_point_of_sale_id'],
            ])->id,
            'sin_api_token_id' => fn (array $attributes) => SinCuis::query()->findOrFail($attributes['sin_cuis_id'])->sin_api_token_id,
            'sin_authorization_id' => fn (array $attributes) => SinCuis::query()->findOrFail($attributes['sin_cuis_id'])->sin_authorization_id,
            'tax_id' => fake()->numerify('##########'),
            'wsdl_url' => SiatWsdlRegistry::CODES,
            'environment_code' => SiatEnvironment::TestingAndPilot,
            'modality_code' => SiatModality::ComputerizedOnline,
            'branch_code' => 0,
            'point_of_sale_code' => 0,
            'transaccion' => true,
            'cufd_code' => fake()->regexify('[A-Z0-9]{24,48}'),
            'control_code' => fake()->regexify('[A-Z0-9]{6,12}'),
            'address' => fake()->streetAddress(),
            'expires_at' => now()->addDay(),
            'message' => 'CUFD generado correctamente.',
            'response' => [
                'RespuestaCufd' => [
                    'transaccion' => true,
                ],
            ],
            'duration_ms' => 120,
            'requested_at' => now(),
        ];
    }
}
