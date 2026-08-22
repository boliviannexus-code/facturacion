<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ManualContingencyInvoiceStatus;
use App\Models\Concerns\AuditsCompanyChanges;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SinManualContingencyInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class SinManualContingencyInvoice extends Model implements Auditable
{
    /** @use HasFactory<SinManualContingencyInvoiceFactory> */
    use AuditsCompanyChanges, BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'sin_invoice_issue_id', 'sin_cafc_range_id', 'sin_significant_event_id',
        'sin_branch_id', 'sin_point_of_sale_id', 'created_by_user_id', 'transcribed_by_user_id',
        'manual_invoice_number', 'document_sector_code', 'manual_status',
        'original_document_path', 'original_document_hash', 'issued_manually_at', 'transcribed_at',
        'customer_id', 'voided_by_user_id', 'customer_name', 'identity_document_type_code',
        'document_number', 'document_complement', 'customer_code', 'payment_method_code',
        'currency_code', 'subtotal_amount', 'discount_amount', 'total_amount', 'observations',
        'void_reason', 'voided_at', 'xml_path', 'xml_hash',
    ];

    protected function casts(): array
    {
        return [
            'manual_invoice_number' => 'integer',
            'document_sector_code' => 'integer',
            'identity_document_type_code' => 'integer',
            'payment_method_code' => 'integer', 'currency_code' => 'integer',
            'subtotal_amount' => 'decimal:5', 'discount_amount' => 'decimal:5', 'total_amount' => 'decimal:5',
            'manual_status' => ManualContingencyInvoiceStatus::class,
            'issued_manually_at' => 'immutable_datetime',
            'transcribed_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SinInvoiceIssue::class, 'sin_invoice_issue_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function cafcRange(): BelongsTo
    {
        return $this->belongsTo(SinCafcRange::class, 'sin_cafc_range_id');
    }

    public function significantEvent(): BelongsTo
    {
        return $this->belongsTo(SinSignificantEvent::class, 'sin_significant_event_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(SinBranch::class, 'sin_branch_id');
    }

    public function pointOfSale(): BelongsTo
    {
        return $this->belongsTo(SinPointOfSale::class, 'sin_point_of_sale_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function transcriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transcribed_by_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SinManualContingencyInvoiceItem::class)->orderBy('line_number');
    }
}
