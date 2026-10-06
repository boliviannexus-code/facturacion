<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InvoiceFiscalStatus;
use App\Enums\InvoiceTestBatchStatus;
use App\Enums\InvoiceTestItemStatus;
use App\Models\InvoiceTestBatch;
use App\Models\InvoiceTestBatchItem;
use App\Services\Billing\InvoiceCancellationReversalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReverseInvoiceTestItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $companyId,
        public readonly int $batchId,
        public readonly int $itemId,
    ) {}

    public function handle(InvoiceCancellationReversalService $reversals): void
    {
        $item = InvoiceTestBatchItem::query()->withoutGlobalScope('company')
            ->with(['batch.user', 'invoice'])
            ->where('company_id', $this->companyId)
            ->where('invoice_test_batch_id', $this->batchId)
            ->findOrFail($this->itemId);

        if (in_array($item->reversal_status, [InvoiceTestItemStatus::Succeeded, InvoiceTestItemStatus::Failed], true)) {
            return;
        }

        $item->update(['reversal_status' => InvoiceTestItemStatus::Running, 'reversal_started_at' => now()]);
        $item->batch->update([
            'reversal_status' => InvoiceTestBatchStatus::Running,
            'reversal_started_at' => $item->batch->reversal_started_at ?? now(),
        ]);

        try {
            $invoice = $reversals->reverse(
                $item->invoice,
                (int) $item->batch->sin_point_of_sale_id,
                $item->batch->user,
            );
            $success = $invoice->fiscal_status === InvoiceFiscalStatus::ReversedInSiat;
            $item->update([
                'reversal_status' => $success ? InvoiceTestItemStatus::Succeeded : InvoiceTestItemStatus::Failed,
                'reversal_message' => $invoice->reversal_message,
                'reversal_finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $item->update([
                'reversal_status' => InvoiceTestItemStatus::Failed,
                'reversal_message' => $exception->getMessage(),
                'reversal_finished_at' => now(),
            ]);
        }

        $this->refreshProgress();
    }

    private function refreshProgress(): void
    {
        DB::transaction(function (): void {
            $batch = InvoiceTestBatch::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($this->batchId);
            $items = InvoiceTestBatchItem::query()->withoutGlobalScope('company')->where('invoice_test_batch_id', $this->batchId);
            $successful = (clone $items)->where('reversal_status', InvoiceTestItemStatus::Succeeded)->count();
            $failed = (clone $items)->where('reversal_status', InvoiceTestItemStatus::Failed)->count();
            $processed = $successful + $failed;
            $finished = $processed >= $batch->reversal_requested_count;
            $batch->update([
                'reversal_processed_count' => $processed,
                'reversal_successful_count' => $successful,
                'reversal_failed_count' => $failed,
                'reversal_status' => $finished
                    ? ($failed ? InvoiceTestBatchStatus::CompletedWithErrors : InvoiceTestBatchStatus::Completed)
                    : InvoiceTestBatchStatus::Running,
                'reversal_finished_at' => $finished ? now() : null,
            ]);
        }, 3);
    }
}
