<?php

namespace Database\Factories;

use App\Enums\SiatEnvironment;
use App\Enums\SiatModality;
use App\Models\Company;
use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinBranch;
use App\Models\SinCuis;
use App\Models\SinPointOfSale;
use App\Services\Siat\SiatWsdlRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SinCuis>
 */
class SinCuisFactory extends Factory
{
    protected $model = SinCuis::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'sin_api_token_id' => fn (array $attributes) => SinApiToken::query()
                ->withoutGlobalScope('company')->where('company_id', $attributes['company_id'])->value('id')
                ?? SinApiToken::factory()->create(['company_id' => $attributes['company_id']])->id,
            'sin_authorization_id' => fn (array $attributes) => SinAuthorization::query()
                ->withoutGlobalScope('company')->where('company_id', $attributes['company_id'])->value('id')
                ?? SinAuthorization::factory()->create(['company_id' => $attributes['company_id']])->id,
            'sin_branch_id' => fn (array $attributes) => SinBranch::factory()->create(['company_id' => $attributes['company_id']])->id,
            'sin_point_of_sale_id' => fn (array $attributes) => SinPointOfSale::factory()->create([
                'company_id' => $attributes['company_id'],
                'sin_branch_id' => $attributes['sin_branch_id'],
            ])->id,
            'tax_id' => fake()->numerify('##########'),
            'wsdl_url' => SiatWsdlRegistry::CODES,
            'environment_code' => SiatEnvironment::TestingAndPilot,
            'modality_code' => SiatModality::ComputerizedOnline,
            'branch_code' => 0,
            'point_of_sale_code' => 0,
            'transaccion' => true,
            'cuis_code' => fake()->regexify('[A-Z0-9]{10,20}'),
            'message' => 'CUIS generado correctamente.',
            'response' => [
                'RespuestaCuis' => [
                    'transaccion' => true,
                ],
            ],
            'duration_ms' => 120,
            'requested_at' => now(),
        ];
    }
}
