<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\SinApiToken;
use App\Services\Siat\SiatWsdlRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SinApiToken>
 */
class SinApiTokenFactory extends Factory
{
    protected $model = SinApiToken::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'api_token' => fake()->regexify('[A-Za-z0-9\-_]{48}'),
            'wsdl_url' => SiatWsdlRegistry::CODES,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
        ];
    }
}
