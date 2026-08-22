<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SaleStatus;
use App\Models\Concerns\AuditsCompanyChanges;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OwenIt\Auditing\Contracts\Auditable;

class Sale extends Model implements Auditable
{
    /** @use HasFactory<SaleFactory> */
    use AuditsCompanyChanges, BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'user_id', 'customer_id', 'sin_point_of_sale_id',
        'issuance_key', 'sale_status', 'document_sector_code', 'economic_activity_code',
        'payment_method_code', 'masked_card_number', 'currency_code', 'subtotal_amount',
        'discount_amount', 'additional_discount_type', 'additional_discount_percentage',
        'total_amount', 'exchange_rate', 'gift_card_amount', 'total_amount_currency',
        'total_amount_subject_to_vat', 'issued_at', 'inventory_applied_at',
        'payment_registered_at', 'commercial_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'sale_status' => SaleStatus::class,
            'document_sector_code' => 'integer',
            'economic_activity_code' => 'integer',
            'payment_method_code' => 'integer',
            'currency_code' => 'integer',
            'subtotal_amount' => 'decimal:5',
            'discount_amount' => 'decimal:5',
            'additional_discount_percentage' => 'decimal:2',
            'total_amount' => 'decimal:5',
            'exchange_rate' => 'decimal:5',
            'gift_card_amount' => 'decimal:5',
            'total_amount_currency' => 'decimal:5',
            'total_amount_subject_to_vat' => 'decimal:5',
            'issued_at' => 'immutable_datetime',
            'inventory_applied_at' => 'immutable_datetime',
            'payment_registered_at' => 'immutable_datetime',
            'commercial_confirmed_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function pointOfSale(): BelongsTo
    {
        return $this->belongsTo(SinPointOfSale::class, 'sin_point_of_sale_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('position');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(SinInvoiceIssue::class);
    }
}
