<?php

namespace Database\Factories;

use App\Enums\ManualContingencyInvoiceStatus;
use App\Models\SinCafcRange;
use App\Models\SinManualContingencyInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SinManualContingencyInvoice> */
class SinManualContingencyInvoiceFactory extends Factory
{
    protected $model = SinManualContingencyInvoice::class;

    public function definition(): array
    {
        return [
            'sin_cafc_range_id' => SinCafcRange::factory(),
            'company_id' => fn (array $a) => SinCafcRange::query()->findOrFail($a['sin_cafc_range_id'])->company_id,
            'sin_branch_id' => fn (array $a) => SinCafcRange::query()->findOrFail($a['sin_cafc_range_id'])->sin_branch_id,
            'sin_point_of_sale_id' => fn (array $a) => SinCafcRange::query()->findOrFail($a['sin_cafc_range_id'])->sin_point_of_sale_id,
            'sin_invoice_issue_id' => null,
            'created_by_user_id' => fn (array $a) => User::factory()->create(['company_id' => $a['company_id']])->id,
            'manual_invoice_number' => 1,
            'document_sector_code' => 1,
            'manual_status' => ManualContingencyInvoiceStatus::PendingTranscription,
            'issued_manually_at' => now(),
        ];
    }
}
