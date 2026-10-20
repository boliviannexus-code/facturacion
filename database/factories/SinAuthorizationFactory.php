<?php

namespace Database\Factories;

use App\Enums\SiatEnvironment;
use App\Enums\SiatModality;
use App\Models\Company;
use App\Models\SinAuthorization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SinAuthorization>
 */
class SinAuthorizationFactory extends Factory
{
    protected $model = SinAuthorization::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'tax_id' => fake()->numerify('##########'),
            'legal_name' => fake()->company(),
            'system_code' => fake()->regexify('[A-Z0-9]{12}'),
            'environment_code' => SiatEnvironment::TestingAndPilot,
            'modality_code' => SiatModality::ComputerizedOnline,
            'branch_code' => 0,
            'point_of_sale_code' => null,
        ];
    }
}
