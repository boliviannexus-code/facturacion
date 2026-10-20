<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SiatAttemptStatus;
use App\Enums\SiatFailureCategory;
use App\Enums\SiatOperation;
use App\Models\Concerns\AuditsCompanyChanges;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SinSiatAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class SinSiatAttempt extends Model implements Auditable
{
    /** @use HasFactory<SinSiatAttemptFactory> */
    use AuditsCompanyChanges, BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'sin_invoice_issue_id', 'sin_invoice_package_id',
        'sin_significant_event_id', 'user_id', 'idempotency_key', 'operation',
        'attempt_number', 'attempt_status', 'failure_category', 'endpoint',
        'request_hash', 'reception_code', 'siat_status_code', 'duration_ms',
        'message', 'request_payload', 'response', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'operation' => SiatOperation::class,
            'attempt_status' => SiatAttemptStatus::class,
            'failure_category' => SiatFailureCategory::class,
            'attempt_number' => 'integer',
            'siat_status_code' => 'integer',
            'duration_ms' => 'integer',
            'request_payload' => 'array',
            'response' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SinInvoiceIssue::class, 'sin_invoice_issue_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SinInvoicePackage::class, 'sin_invoice_package_id');
    }

    public function significantEvent(): BelongsTo
    {
        return $this->belongsTo(SinSignificantEvent::class, 'sin_significant_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SinResponseMessage::class, 'sin_siat_attempt_id');
    }

    public function fiscalTransitions(): HasMany
    {
        return $this->hasMany(SinFiscalStatusHistory::class, 'sin_siat_attempt_id');
    }
}
