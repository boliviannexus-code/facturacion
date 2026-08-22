<?php

namespace Database\Factories;

use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoicePackageStatus;
use App\Enums\SiatEnvironment;
use App\Enums\SiatModality;
use App\Models\Company;
use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinBranch;
use App\Models\SinCufd;
use App\Models\SinCuis;
use App\Models\SinInvoicePackage;
use App\Models\SinPointOfSale;
use App\Models\SinSignificantEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SinInvoicePackage> */
class SinInvoicePackageFactory extends Factory
{
    protected $model = SinInvoicePackage::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'sin_branch_id' => fn (array $a) => SinBranch::factory()->create(['company_id' => $a['company_id']])->id,
            'sin_point_of_sale_id' => fn (array $a) => SinPointOfSale::factory()->create(['company_id' => $a['company_id'], 'sin_branch_id' => $a['sin_branch_id']])->id,
            'sin_api_token_id' => fn (array $a) => SinApiToken::query()
                ->withoutGlobalScope('company')->where('company_id', $a['company_id'])->value('id')
                ?? SinApiToken::factory()->create(['company_id' => $a['company_id']])->id,
            'sin_authorization_id' => fn (array $a) => SinAuthorization::query()
                ->withoutGlobalScope('company')->where('company_id', $a['company_id'])->value('id')
                ?? SinAuthorization::factory()->create(['company_id' => $a['company_id']])->id,
            'sin_cuis_id' => fn (array $a) => SinCuis::factory()->create([
                'company_id' => $a['company_id'],
                'sin_api_token_id' => $a['sin_api_token_id'],
                'sin_authorization_id' => $a['sin_authorization_id'],
                'sin_branch_id' => $a['sin_branch_id'],
                'sin_point_of_sale_id' => $a['sin_point_of_sale_id'],
            ])->id,
            'sin_cufd_id' => fn (array $a) => SinCufd::factory()->create([
                'company_id' => $a['company_id'],
                'sin_api_token_id' => $a['sin_api_token_id'],
                'sin_authorization_id' => $a['sin_authorization_id'],
                'sin_branch_id' => $a['sin_branch_id'],
                'sin_point_of_sale_id' => $a['sin_point_of_sale_id'],
                'sin_cuis_id' => $a['sin_cuis_id'],
            ])->id,
            'sin_significant_event_id' => fn (array $a) => SinSignificantEvent::factory()->create([
                'company_id' => $a['company_id'],
                'sin_api_token_id' => $a['sin_api_token_id'],
                'sin_authorization_id' => $a['sin_authorization_id'],
                'sin_branch_id' => $a['sin_branch_id'],
                'sin_point_of_sale_id' => $a['sin_point_of_sale_id'],
                'sin_cuis_id' => $a['sin_cuis_id'],
                'sin_cufd_id' => $a['sin_cufd_id'],
            ])->id,
            'created_by_user_id' => fn (array $a) => User::factory()->create(['company_id' => $a['company_id']])->id,
            'package_key' => (string) Str::uuid(),
            'package_number' => fake()->unique()->numberBetween(1, 999999),
            'emission_mode' => InvoiceEmissionMode::OfflineDigital,
            'package_status' => InvoicePackageStatus::Created,
            'invoice_count' => 0,
            'tax_id' => fn (array $a) => SinAuthorization::query()->findOrFail($a['sin_authorization_id'])->tax_id,
            'environment_code' => SiatEnvironment::TestingAndPilot,
            'modality_code' => SiatModality::ComputerizedOnline,
            'emission_type_code' => 2,
            'document_sector_code' => 1,
            'invoice_document_type_code' => 1,
            'branch_code' => 0,
            'point_of_sale_code' => 0,
        ];
    }
}
