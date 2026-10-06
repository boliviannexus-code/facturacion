<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SaleStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SinPointOfSale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Sale> */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => fn (array $a) => User::factory()->create(['company_id' => $a['company_id']])->id,
            'customer_id' => fn (array $a) => Customer::factory()->create(['company_id' => $a['company_id']])->id,
            'sin_point_of_sale_id' => fn (array $a) => SinPointOfSale::factory()->create(['company_id' => $a['company_id']])->id,
            'issuance_key' => (string) Str::uuid(),
            'sale_status' => SaleStatus::Confirmed,
            'document_sector_code' => 1,
            'economic_activity_code' => 620100,
            'payment_method_code' => 1,
            'masked_card_number' => null,
            'currency_code' => 1,
            'subtotal_amount' => 100,
            'discount_amount' => 0,
            'additional_discount_type' => 'FIXED',
            'additional_discount_percentage' => null,
            'total_amount' => 100,
            'exchange_rate' => 1,
            'gift_card_amount' => 0,
            'total_amount_currency' => 100,
            'total_amount_subject_to_vat' => 100,
            'issued_at' => now(),
        ];
    }
}
