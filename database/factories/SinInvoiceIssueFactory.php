<?php

namespace Database\Factories;

use App\Enums\InvoiceCommercialStatus;
use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\SiatEnvironment;
use App\Enums\SiatModality;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinBranch;
use App\Models\SinCufd;
use App\Models\SinCuis;
use App\Models\SinInvoiceIssue;
use App\Models\SinPointOfSale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SinInvoiceIssue> */
class SinInvoiceIssueFactory extends Factory
{
    protected $model = SinInvoiceIssue::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => fn (array $a) => User::factory()->create(['company_id' => $a['company_id']])->id,
            'customer_id' => fn (array $a) => Customer::factory()->create(['company_id' => $a['company_id']])->id,
            'sin_branch_id' => fn (array $a) => SinBranch::factory()->create(['company_id' => $a['company_id']])->id,
            'sin_point_of_sale_id' => fn (array $a) => SinPointOfSale::factory()->create(['company_id' => $a['company_id'], 'sin_branch_id' => $a['sin_branch_id']])->id,
            'sin_api_token_id' => fn (array $a) => SinApiToken::query()
                ->withoutGlobalScope('company')->where('company_id', $a['company_id'])->value('id')
                ?? SinApiToken::factory()->create(['company_id' => $a['company_id']])->id,
            'sin_authorization_id' => fn (array $a) => SinAuthorization::query()
                ->withoutGlobalScope('company')->where('company_id', $a['company_id'])->value('id')
                ?? SinAuthorization::factory()->create(['company_id' => $a['company_id']])->id,
            'sin_cuis_id' => fn (array $a) => SinCuis::factory()->create(['company_id' => $a['company_id'], 'sin_api_token_id' => $a['sin_api_token_id'], 'sin_authorization_id' => $a['sin_authorization_id'], 'sin_branch_id' => $a['sin_branch_id'], 'sin_point_of_sale_id' => $a['sin_point_of_sale_id']])->id,
            'sin_cufd_id' => fn (array $a) => SinCufd::factory()->create(['company_id' => $a['company_id'], 'sin_api_token_id' => $a['sin_api_token_id'], 'sin_authorization_id' => $a['sin_authorization_id'], 'sin_branch_id' => $a['sin_branch_id'], 'sin_point_of_sale_id' => $a['sin_point_of_sale_id'], 'sin_cuis_id' => $a['sin_cuis_id']])->id,
            'tax_id' => fake()->numerify('##########'),
            'environment_code' => SiatEnvironment::TestingAndPilot,
            'modality_code' => SiatModality::ComputerizedOnline,
            'emission_type_code' => 1,
            'document_sector_code' => 1,
            'invoice_document_type_code' => 1,
            'emission_mode' => InvoiceEmissionMode::Online,
            'commercial_status' => InvoiceCommercialStatus::Confirmed,
            'fiscal_status' => InvoiceFiscalStatus::PendingOnlineSend,
            'branch_code' => 0,
            'point_of_sale_code' => 0,
            'attempted_invoice_number' => fake()->unique()->numberBetween(1, 999999),
            'cuf' => strtoupper(fake()->unique()->bothify('????????????????################')),
            'cufd_code' => strtoupper(fake()->bothify('????????????############')),
            'status_label' => 'Pendiente',
            'transaccion' => false,
            'subtotal_amount' => 100,
            'discount_amount' => 0,
            'total_amount' => 100,
            'taxable_amount' => 100,
            'payload' => ['cabecera' => [], 'detalle' => []],
            'issued_at' => now(),
        ];
    }
}
