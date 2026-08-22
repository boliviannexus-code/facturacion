<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InvoiceFiscalStatus;
use App\Enums\InvoiceTestBatchStatus;
use App\Enums\InvoiceTestItemStatus;
use App\Models\InvoiceTestBatch;
use App\Models\InvoiceTestBatchItem;
use App\Services\Billing\InvoiceCancellationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CancelInvoiceTestItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $companyId, public readonly int $batchId, public readonly int $itemId) {}

    public function handle(InvoiceCancellationService $cancellations): void
    {
        $item = InvoiceTestBatchItem::query()->withoutGlobalScope('company')
            ->with(['batch.user', 'invoice'])->where('company_id', $this->companyId)->findOrFail($this->itemId);

        if (in_array($item->cancellation_status, [InvoiceTestItemStatus::Succeeded, InvoiceTestItemStatus::Failed], true)) {
            return;
        }

        $item->update(['cancellation_status' => InvoiceTestItemStatus::Running, 'cancellation_started_at' => now()]);
        $item->batch->update(['cancellation_status' => InvoiceTestBatchStatus::Running,
            'cancellation_started_at' => $item->batch->cancellation_started_at ?? now()]);

        try {
            $invoice = $cancellations->cancel($item->invoice, (int) $item->batch->sin_point_of_sale_id,
                (int) $item->batch->cancellation_reason_code, $item->batch->user);
            $success = $invoice->fiscal_status === InvoiceFiscalStatus::CancelledInSiat;
            $item->update(['cancellation_status' => $success ? InvoiceTestItemStatus::Succeeded : InvoiceTestItemStatus::Failed,
                'cancellation_message' => $invoice->cancellation_message, 'cancellation_finished_at' => now()]);
        } catch (Throwable $exception) {
            report($exception);
            $item->update(['cancellation_status' => InvoiceTestItemStatus::Failed,
                'cancellation_message' => $exception->getMessage(), 'cancellation_finished_at' => now()]);
        }

        $this->refreshProgress();
    }

    private function refreshProgress(): void
    {
        DB::transaction(function (): void {
            $batch = InvoiceTestBatch::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($this->batchId);
            $items = InvoiceTestBatchItem::query()->withoutGlobalScope('company')->where('invoice_test_batch_id', $this->batchId);
            $successful = (clone $items)->where('cancellation_status', InvoiceTestItemStatus::Succeeded)->count();
            $failed = (clone $items)->where('cancellation_status', InvoiceTestItemStatus::Failed)->count();
            $processed = $successful + $failed;
            $finished = $processed >= $batch->cancellation_requested_count;
            $batch->update(['cancellation_processed_count' => $processed, 'cancellation_successful_count' => $successful,
                'cancellation_failed_count' => $failed, 'cancellation_status' => $finished
                    ? ($failed ? InvoiceTestBatchStatus::CompletedWithErrors : InvoiceTestBatchStatus::Completed)
                    : InvoiceTestBatchStatus::Running, 'cancellation_finished_at' => $finished ? now() : null]);
        }, 3);
    }
}
