<?php

namespace App\Models;

use App\Models\Concerns\AuditsCompanyChanges;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class Product extends Model implements Auditable
{
    /** @use HasFactory<ProductFactory> */
    use AuditsCompanyChanges, BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'product_category_id',
        'measurement_unit_code',
        'internal_code',
        'description',
        'economic_activity_code',
        'siat_product_code',
        'unit_price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'measurement_unit_code' => 'integer',
            'siat_product_code' => 'integer',
            'unit_price' => 'decimal:5',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id')->withTrashed();
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function manualContingencyInvoiceItems(): HasMany
    {
        return $this->hasMany(SinManualContingencyInvoiceItem::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
