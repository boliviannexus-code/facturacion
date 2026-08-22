<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InvoiceFiscalStatus;
use App\Enums\InvoiceIssuanceDecision;
use App\Enums\InvoiceTestBatchStatus;
use App\Enums\InvoiceTestItemStatus;
use App\Models\InvoiceTestBatch;
use App\Models\InvoiceTestBatchItem;
use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\InvoiceTestBatchService;
use App\Services\Billing\SaleCreationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class IssueInvoiceTestItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $companyId,
        public readonly int $batchId,
        public readonly int $itemId,
    ) {}

    public function handle(
        InvoiceTestBatchService $testBatches,
        SaleCreationService $sales,
        InvoiceIssuanceService $issuance,
    ): void {
        $item = InvoiceTestBatchItem::query()->withoutGlobalScope('company')
            ->with(['batch.user', 'batch.product'])
            ->where('company_id', $this->companyId)
            ->where('invoice_test_batch_id', $this->batchId)
            ->findOrFail($this->itemId);

        if (in_array($item->item_status, [InvoiceTestItemStatus::Succeeded, InvoiceTestItemStatus::Failed], true)) {
            return;
        }

        $batch = $item->batch;
        $item->update(['stage' => 'ISSUING_ONLINE', 'item_status' => InvoiceTestItemStatus::Running, 'started_at' => now()]);
        $batch->update([
            'batch_status' => InvoiceTestBatchStatus::Running,
            'started_at' => $batch->started_at ?? now(),
        ]);

        try {
            $testBatches->assertPilotEnvironment($this->companyId);
            $sale = $sales->create($batch->user, [
                'document_sector_code' => $batch->document_sector_code,
                'sin_point_of_sale_id' => $batch->sin_point_of_sale_id,
                'customer_id' => $batch->customer_id,
                'issuance_key' => $item->issuance_key,
                'economic_activity_code' => $batch->economic_activity_code,
                'payment_method_code' => $batch->payment_method_code,
                'currency_code' => $batch->currency_code,
                'additional_discount_type' => 'FIXED',
                'total_discount' => 0,
                'exchange_rate' => 1,
                'gift_card_amount' => 0,
                'items' => [[
                    'product_id' => $batch->product_id,
                    'description' => $batch->product->description,
                    'quantity' => $batch->quantity,
                    'unit_price' => $batch->unit_price,
                    'discount' => 0,
                    'discount_type' => 'FIXED',
                ]],
            ]);
            $result = $issuance->issue($sale, allowContingency: false);
            $invoice = $result->invoice;
            $successful = $result->decision === InvoiceIssuanceDecision::Online
                && $invoice?->fiscal_status === InvoiceFiscalStatus::Validated;

            $item->update([
                'sale_id' => $sale->id,
                'sin_invoice_issue_id' => $invoice?->id,
                'item_status' => $successful ? InvoiceTestItemStatus::Succeeded : InvoiceTestItemStatus::Failed,
                'stage' => $successful ? 'COMPLETED' : 'FAILED',
                'message' => $result->message,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $item->update([
                'item_status' => InvoiceTestItemStatus::Failed,
                'stage' => 'FAILED',
                'message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
        }

        $this->refreshBatchProgress();
    }

    private function refreshBatchProgress(): void
    {
        DB::transaction(function (): void {
            $batch = InvoiceTestBatch::query()->withoutGlobalScope('company')
                ->where('company_id', $this->companyId)
                ->lockForUpdate()
                ->findOrFail($this->batchId);
            $items = InvoiceTestBatchItem::query()->withoutGlobalScope('company')
                ->where('company_id', $this->companyId)
                ->where('invoice_test_batch_id', $this->batchId);
            $successful = (clone $items)->where('item_status', InvoiceTestItemStatus::Succeeded)->count();
            $failed = (clone $items)->where('item_status', InvoiceTestItemStatus::Failed)->count();
            $processed = $successful + $failed;
            $finished = $processed >= $batch->requested_count;

            $batch->update([
                'processed_count' => $processed,
                'successful_count' => $successful,
                'failed_count' => $failed,
                'batch_status' => $finished
                    ? ($failed > 0 ? InvoiceTestBatchStatus::CompletedWithErrors : InvoiceTestBatchStatus::Completed)
                    : InvoiceTestBatchStatus::Running,
                'finished_at' => $finished ? now() : null,
            ]);
        }, 3);
    }
}
