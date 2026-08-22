<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\SinManualContingencyInvoice;
use App\Models\SinManualContingencyInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SinManualContingencyInvoiceItem> */
class SinManualContingencyInvoiceItemFactory extends Factory
{
    protected $model = SinManualContingencyInvoiceItem::class;

    public function definition(): array
    {
        return [
            'sin_manual_contingency_invoice_id' => SinManualContingencyInvoice::factory(),
            'company_id' => fn (array $a) => SinManualContingencyInvoice::query()->withoutGlobalScope('company')->findOrFail($a['sin_manual_contingency_invoice_id'])->company_id,
            'product_id' => fn (array $a) => Product::factory()->create(['company_id' => $a['company_id']])->id,
            'line_number' => 1,
            'economic_activity_code' => '620100',
            'siat_product_code' => 83141,
            'internal_code' => 'SRV-1',
            'description' => 'Servicio de prueba',
            'measurement_unit_code' => 58,
            'quantity' => 1,
            'unit_price' => 100,
            'discount_amount' => 0,
            'subtotal_amount' => 100,
        ];
    }
}
