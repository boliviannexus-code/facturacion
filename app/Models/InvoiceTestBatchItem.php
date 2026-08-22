<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceTestItemStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InvoiceTestBatchItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'invoice_test_batch_id', 'sale_id', 'sin_invoice_issue_id',
        'sin_significant_event_id', 'sin_invoice_package_id',
        'issuance_key', 'position', 'stage', 'item_status', 'message', 'started_at', 'finished_at',
        'cancellation_status', 'cancellation_message', 'cancellation_started_at', 'cancellation_finished_at',
        'reversal_status', 'reversal_message', 'reversal_started_at', 'reversal_finished_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'item_status' => InvoiceTestItemStatus::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'cancellation_status' => InvoiceTestItemStatus::class,
            'cancellation_started_at' => 'immutable_datetime',
            'cancellation_finished_at' => 'immutable_datetime',
            'reversal_status' => InvoiceTestItemStatus::class,
            'reversal_started_at' => 'immutable_datetime',
            'reversal_finished_at' => 'immutable_datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InvoiceTestBatch::class, 'invoice_test_batch_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SinInvoiceIssue::class, 'sin_invoice_issue_id');
    }

    public function significantEvent(): BelongsTo
    {
        return $this->belongsTo(SinSignificantEvent::class, 'sin_significant_event_id');
    }

    public function invoicePackage(): BelongsTo
    {
        return $this->belongsTo(SinInvoicePackage::class, 'sin_invoice_package_id');
    }
}
