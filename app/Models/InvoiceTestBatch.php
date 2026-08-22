<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceTestBatchStatus;
use App\Enums\InvoiceTestMode;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class InvoiceTestBatch extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'user_id', 'sin_point_of_sale_id', 'customer_id', 'product_id',
        'batch_key', 'batch_status', 'test_mode', 'requested_count', 'invoices_per_cycle', 'processed_count',
        'successful_count', 'failed_count', 'document_sector_code', 'event_code', 'event_description', 'economic_activity_code',
        'payment_method_code', 'currency_code', 'quantity', 'unit_price',
        'started_at', 'finished_at',
        'cancellation_status', 'cancellation_reason_code', 'cancellation_requested_count',
        'cancellation_processed_count', 'cancellation_successful_count', 'cancellation_failed_count',
        'cancellation_started_at', 'cancellation_finished_at',
        'reversal_status', 'reversal_requested_count', 'reversal_processed_count',
        'reversal_successful_count', 'reversal_failed_count', 'reversal_started_at', 'reversal_finished_at',
    ];

    protected function casts(): array
    {
        return [
            'batch_status' => InvoiceTestBatchStatus::class,
            'test_mode' => InvoiceTestMode::class,
            'requested_count' => 'integer',
            'invoices_per_cycle' => 'integer',
            'processed_count' => 'integer',
            'successful_count' => 'integer',
            'failed_count' => 'integer',
            'document_sector_code' => 'integer',
            'event_code' => 'integer',
            'economic_activity_code' => 'integer',
            'payment_method_code' => 'integer',
            'currency_code' => 'integer',
            'quantity' => 'decimal:5',
            'unit_price' => 'decimal:5',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'cancellation_status' => InvoiceTestBatchStatus::class,
            'cancellation_reason_code' => 'integer',
            'cancellation_requested_count' => 'integer',
            'cancellation_processed_count' => 'integer',
            'cancellation_successful_count' => 'integer',
            'cancellation_failed_count' => 'integer',
            'cancellation_started_at' => 'immutable_datetime',
            'cancellation_finished_at' => 'immutable_datetime',
            'reversal_status' => InvoiceTestBatchStatus::class,
            'reversal_requested_count' => 'integer',
            'reversal_processed_count' => 'integer',
            'reversal_successful_count' => 'integer',
            'reversal_failed_count' => 'integer',
            'reversal_started_at' => 'immutable_datetime',
            'reversal_finished_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pointOfSale(): BelongsTo
    {
        return $this->belongsTo(SinPointOfSale::class, 'sin_point_of_sale_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceTestBatchItem::class)->orderBy('position');
    }
}
