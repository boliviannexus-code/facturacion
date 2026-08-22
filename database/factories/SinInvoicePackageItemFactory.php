<?php

namespace Database\Factories;

use App\Enums\InvoiceFiscalStatus;
use App\Models\SinInvoiceIssue;
use App\Models\SinInvoicePackage;
use App\Models\SinInvoicePackageItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SinInvoicePackageItem> */
class SinInvoicePackageItemFactory extends Factory
{
    protected $model = SinInvoicePackageItem::class;

    public function definition(): array
    {
        return [
            'sin_invoice_package_id' => SinInvoicePackage::factory()->state(['invoice_count' => 1]),
            'company_id' => fn (array $a) => SinInvoicePackage::query()->findOrFail($a['sin_invoice_package_id'])->company_id,
            'sin_invoice_issue_id' => function (array $a): int {
                $package = SinInvoicePackage::query()->findOrFail($a['sin_invoice_package_id']);

                return SinInvoiceIssue::factory()->create([
                    'company_id' => $package->company_id,
                    'sin_api_token_id' => $package->sin_api_token_id,
                    'sin_authorization_id' => $package->sin_authorization_id,
                    'sin_branch_id' => $package->sin_branch_id,
                    'sin_point_of_sale_id' => $package->sin_point_of_sale_id,
                    'sin_cuis_id' => $package->sin_cuis_id,
                    'sin_cufd_id' => $package->sin_cufd_id,
                    'sin_significant_event_id' => $package->sin_significant_event_id,
                    'tax_id' => $package->tax_id,
                    'environment_code' => $package->environment_code,
                    'modality_code' => $package->modality_code,
                    'emission_type_code' => $package->emission_type_code,
                    'document_sector_code' => $package->document_sector_code,
                    'invoice_document_type_code' => $package->invoice_document_type_code,
                    'branch_code' => $package->branch_code,
                    'point_of_sale_code' => $package->point_of_sale_code,
                    'emission_mode' => $package->emission_mode,
                    'fiscal_status' => InvoiceFiscalStatus::OfflineIssued,
                ])->id;
            },
            'position' => 1,
            'cuf' => fn (array $a) => SinInvoiceIssue::query()->findOrFail($a['sin_invoice_issue_id'])->cuf,
            'file_hash' => hash('sha256', fake()->uuid()),
        ];
    }
}
