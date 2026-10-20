<?php

namespace App\Models;

use App\Enums\InvoiceCommercialStatus;
use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\SiatEnvironment;
use App\Enums\SiatFailureCategory;
use App\Enums\SiatModality;
use App\Models\Concerns\AuditsCompanyChanges;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OwenIt\Auditing\Contracts\Auditable;

class SinInvoiceIssue extends Model implements Auditable
{
    use AuditsCompanyChanges, BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'sale_id',
        'user_id',
        'customer_id',
        'sin_api_token_id',
        'sin_authorization_id',
        'sin_branch_id',
        'sin_point_of_sale_id',
        'sin_cuis_id',
        'sin_cufd_id',
        'sin_significant_event_id',
        'tax_id',
        'environment_code',
        'modality_code',
        'emission_type_code',
        'document_sector_code',
        'invoice_document_type_code',
        'emission_mode',
        'commercial_status',
        'fiscal_status',
        'failure_category',
        'branch_code',
        'point_of_sale_code',
        'attempted_invoice_number',
        'invoice_number',
        'cuf',
        'cufd_code',
        'control_code',
        'reception_code',
        'status_code',
        'status_label',
        'transaccion',
        'xml_path',
        'gzip_path',
        'pdf_path',
        'pdf_hash',
        'hash_file',
        'subtotal_amount',
        'discount_amount',
        'total_amount',
        'taxable_amount',
        'payload',
        'response',
        'message',
        'duration_ms',
        'issued_at',
        'sent_at',
        'issuance_notified_at',
        'issuance_notification_error',
        'cancellation_requested_by_user_id',
        'cancellation_point_of_sale_id',
        'cancellation_reason_code',
        'cancellation_reason',
        'cancellation_status_code',
        'cancellation_response',
        'cancellation_message',
        'cancellation_requested_at',
        'cancelled_at',
        'cancellation_notified_at',
        'cancellation_notification_error',
        'reversal_requested_by_user_id',
        'reversal_point_of_sale_id',
        'reversal_status_code',
        'reversal_response',
        'reversal_message',
        'reversal_requested_at',
        'reversed_at',
        'reversal_notified_at',
        'reversal_notification_error',
    ];

    protected function casts(): array
    {
        return [
            'environment_code' => SiatEnvironment::class,
            'modality_code' => SiatModality::class,
            'emission_type_code' => 'integer',
            'document_sector_code' => 'integer',
            'invoice_document_type_code' => 'integer',
            'emission_mode' => InvoiceEmissionMode::class,
            'commercial_status' => InvoiceCommercialStatus::class,
            'fiscal_status' => InvoiceFiscalStatus::class,
            'failure_category' => SiatFailureCategory::class,
            'branch_code' => 'integer',
            'point_of_sale_code' => 'integer',
            'attempted_invoice_number' => 'integer',
            'invoice_number' => 'integer',
            'status_code' => 'integer',
            'transaccion' => 'boolean',
            'subtotal_amount' => 'decimal:5',
            'discount_amount' => 'decimal:5',
            'total_amount' => 'decimal:5',
            'taxable_amount' => 'decimal:5',
            'payload' => 'array',
            'response' => 'array',
            'duration_ms' => 'integer',
            'issued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'issuance_notified_at' => 'immutable_datetime',
            'cancellation_reason_code' => 'integer',
            'cancellation_status_code' => 'integer',
            'cancellation_response' => 'array',
            'cancellation_requested_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'cancellation_notified_at' => 'immutable_datetime',
            'reversal_status_code' => 'integer',
            'reversal_response' => 'array',
            'reversal_requested_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
            'reversal_notified_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(SinAuthorization::class, 'sin_authorization_id');
    }

    public function apiToken(): BelongsTo
    {
        return $this->belongsTo(SinApiToken::class, 'sin_api_token_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(SinBranch::class, 'sin_branch_id')->withoutGlobalScope('company');
    }

    public function pointOfSale(): BelongsTo
    {
        return $this->belongsTo(SinPointOfSale::class, 'sin_point_of_sale_id')->withoutGlobalScope('company');
    }

    public function cuis(): BelongsTo
    {
        return $this->belongsTo(SinCuis::class, 'sin_cuis_id')->withoutGlobalScope('company');
    }

    public function cufd(): BelongsTo
    {
        return $this->belongsTo(SinCufd::class, 'sin_cufd_id')->withoutGlobalScope('company');
    }

    public function significantEvents(): HasMany
    {
        return $this->hasMany(SinSignificantEvent::class, 'sin_invoice_issue_id');
    }

    public function significantEvent(): BelongsTo
    {
        return $this->belongsTo(SinSignificantEvent::class, 'sin_significant_event_id');
    }

    public function allowsSignificantEvent(): bool
    {
        return ! $this->transaccion
            && $this->failure_category?->allowsSignificantEvent() === true;
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(SinSiatAttempt::class, 'sin_invoice_issue_id');
    }

    public function fiscalStatusHistory(): HasMany
    {
        return $this->hasMany(SinFiscalStatusHistory::class, 'sin_invoice_issue_id');
    }

    public function packageItem(): HasOne
    {
        return $this->hasOne(SinInvoicePackageItem::class, 'sin_invoice_issue_id');
    }

    public function manualContingency(): HasOne
    {
        return $this->hasOne(SinManualContingencyInvoice::class, 'sin_invoice_issue_id');
    }
}
