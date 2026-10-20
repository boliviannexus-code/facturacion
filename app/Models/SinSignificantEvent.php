<?php

namespace App\Models;

use App\Enums\SignificantEventStatus;
use App\Models\Concerns\AuditsCompanyChanges;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SinSignificantEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OwenIt\Auditing\Contracts\Auditable;

class SinSignificantEvent extends Model implements Auditable
{
    /** @use HasFactory<SinSignificantEventFactory> */
    use AuditsCompanyChanges, BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'user_id', 'updated_by_user_id', 'registered_by_user_id',
        'closed_by_user_id', 'sin_invoice_issue_id', 'sin_api_token_id',
        'sin_authorization_id', 'sin_branch_id', 'sin_point_of_sale_id',
        'sin_cuis_id', 'sin_cufd_id', 'recovery_sin_cufd_id', 'event_code', 'event_description',
        'event_status', 'started_at', 'ended_at', 'detected_at',
        'recovery_detected_at', 'reception_code', 'registration_claim', 'registration_claimed_at',
        'transaccion', 'status_label', 'request_payload', 'response', 'message',
        'duration_ms', 'registered_at', 'closed_at', 'expires_at',
        'manual_review_required', 'requires_manual_processing', 'administrative_correction_reason',
        'administratively_corrected_by_user_id', 'administratively_corrected_at',
    ];

    protected function casts(): array
    {
        return [
            'event_code' => 'integer',
            'event_status' => SignificantEventStatus::class,
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'detected_at' => 'immutable_datetime',
            'recovery_detected_at' => 'immutable_datetime',
            'registration_claimed_at' => 'immutable_datetime',
            'transaccion' => 'boolean',
            'request_payload' => 'array',
            'response' => 'array',
            'duration_ms' => 'integer',
            'registered_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'manual_review_required' => 'boolean',
            'requires_manual_processing' => 'boolean',
            'administratively_corrected_at' => 'immutable_datetime',
        ];
    }

    public function invoiceIssue(): BelongsTo
    {
        return $this->belongsTo(SinInvoiceIssue::class, 'sin_invoice_issue_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function apiToken(): BelongsTo
    {
        return $this->belongsTo(SinApiToken::class, 'sin_api_token_id');
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(SinAuthorization::class, 'sin_authorization_id');
    }

    public function cufd(): BelongsTo
    {
        return $this->belongsTo(SinCufd::class, 'sin_cufd_id');
    }

    public function recoveryCufd(): BelongsTo
    {
        return $this->belongsTo(SinCufd::class, 'recovery_sin_cufd_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(SinBranch::class, 'sin_branch_id');
    }

    public function pointOfSale(): BelongsTo
    {
        return $this->belongsTo(SinPointOfSale::class, 'sin_point_of_sale_id');
    }

    public function cuis(): BelongsTo
    {
        return $this->belongsTo(SinCuis::class, 'sin_cuis_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function administrativeCorrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administratively_corrected_by_user_id');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(SinInvoicePackage::class, 'sin_significant_event_id');
    }

    public function invoiceIssues(): HasMany
    {
        return $this->hasMany(SinInvoiceIssue::class, 'sin_significant_event_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(SinSiatAttempt::class, 'sin_significant_event_id');
    }

    public function manualInvoices(): HasMany
    {
        return $this->hasMany(SinManualContingencyInvoice::class, 'sin_significant_event_id');
    }

    public function cafcRange(): HasOne
    {
        return $this->hasOne(SinCafcRange::class, 'sin_significant_event_id');
    }
}
