<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SinFiscalStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SinFiscalStatusHistory extends Model
{
    /** @use HasFactory<SinFiscalStatusHistoryFactory> */
    use BelongsToCompany, HasFactory;

    protected $table = 'sin_fiscal_status_history';

    protected $fillable = [
        'company_id', 'sin_invoice_issue_id', 'sin_siat_attempt_id',
        'sin_significant_event_id', 'sin_invoice_package_id', 'user_id',
        'from_status', 'to_status', 'emission_mode', 'reason_code', 'reason',
        'metadata', 'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => InvoiceFiscalStatus::class,
            'to_status' => InvoiceFiscalStatus::class,
            'emission_mode' => InvoiceEmissionMode::class,
            'metadata' => 'array',
            'changed_at' => 'immutable_datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SinInvoiceIssue::class, 'sin_invoice_issue_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(SinSiatAttempt::class, 'sin_siat_attempt_id');
    }

    public function significantEvent(): BelongsTo
    {
        return $this->belongsTo(SinSignificantEvent::class, 'sin_significant_event_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SinInvoicePackage::class, 'sin_invoice_package_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
