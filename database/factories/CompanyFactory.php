<?php

namespace Database\Factories;

use App\Enums\InvoicePrintFormat;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'legal_name' => fake()->company().' SRL',
            'tax_id' => fake()->unique()->numerify('########'),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'address' => fake()->address(),
            'city' => fake()->city(),
            'country' => 'Bolivia',
            'report_footer' => fake()->optional()->sentence(),
            'invoice_print_format' => InvoicePrintFormat::HalfPage->value,
            'is_active' => true,
        ];
    }
}
