<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SinManualContingencyInvoiceItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SinManualContingencyInvoiceItem extends Model
{
    /** @use HasFactory<SinManualContingencyInvoiceItemFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'sin_manual_contingency_invoice_id', 'product_id', 'line_number',
        'economic_activity_code', 'siat_product_code', 'internal_code', 'description',
        'measurement_unit_code', 'quantity', 'unit_price', 'discount_amount', 'subtotal_amount',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer', 'siat_product_code' => 'integer', 'measurement_unit_code' => 'integer',
            'quantity' => 'decimal:5', 'unit_price' => 'decimal:5',
            'discount_amount' => 'decimal:5', 'subtotal_amount' => 'decimal:5',
        ];
    }

    public function manualInvoice(): BelongsTo
    {
        return $this->belongsTo(SinManualContingencyInvoice::class, 'sin_manual_contingency_invoice_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
