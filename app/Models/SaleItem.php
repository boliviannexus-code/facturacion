<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'sale_id', 'product_id', 'position', 'internal_code',
        'description', 'economic_activity_code', 'siat_product_code',
        'measurement_unit_code', 'quantity', 'unit_price', 'discount_amount',
        'subtotal_amount', 'discount_type', 'discount_percentage',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'siat_product_code' => 'integer',
            'measurement_unit_code' => 'integer',
            'quantity' => 'decimal:5',
            'unit_price' => 'decimal:5',
            'discount_amount' => 'decimal:5',
            'discount_percentage' => 'decimal:2',
            'subtotal_amount' => 'decimal:5',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
