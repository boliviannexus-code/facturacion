<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InvoiceFiscalStatus;
use App\Enums\InvoicePackageStatus;
use App\Enums\InvoiceTestBatchStatus;
use App\Enums\InvoiceTestItemStatus;
use App\Enums\SignificantEventStatus;
use App\Models\InvoiceTestBatch;
use App\Models\InvoiceTestBatchItem;
use App\Models\SinInvoiceIssue;
use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\InvoiceTestBatchService;
use App\Services\Billing\SaleCreationService;
use App\Services\Siat\ContingencyRecoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

final class ProcessOfflineContingencyTestItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $companyId,
        public readonly int $batchId,
        public readonly int $itemId,
    ) {
        $this->onQueue('siat-packages');
        $this->afterCommit();
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("offline-test:{$this->companyId}:{$this->batchId}"))
            ->releaseAfter(5)->expireAfter(1830)];
    }

    public function handle(
        InvoiceTestBatchService $testBatches,
        SaleCreationService $sales,
        InvoiceIssuanceService $issuance,
        ContingencyRecoveryService $recovery,
    ): void {
        $item = $this->item();
        if (in_array($item->item_status, [InvoiceTestItemStatus::Succeeded, InvoiceTestItemStatus::Failed], true)) {
            return;
        }

        $batch = $item->batch;
        $testBatches->assertPilotEnvironment($this->companyId);
        $item->update([
            'item_status' => InvoiceTestItemStatus::Running,
            'started_at' => $item->started_at ?? now(),
        ]);
        $batch->update([
            'batch_status' => InvoiceTestBatchStatus::Running,
            'started_at' => $batch->started_at ?? now(),
        ]);

        $issuedInCycle = $item->significantEvent
            ? SinInvoiceIssue::query()->withoutGlobalScope('company')
                ->where('sin_significant_event_id', $item->sin_significant_event_id)->count()
            : 0;

        for ($invoicePosition = $issuedInCycle + 1; $invoicePosition <= $batch->invoices_per_cycle; $invoicePosition++) {
            $issuanceKey = $invoicePosition === 1
                ? $item->issuance_key
                : Uuid::uuid5(Uuid::NAMESPACE_URL, "invoice-test-cycle:{$item->id}:{$invoicePosition}")->toString();
            $sale = $invoicePosition === 1 && $item->sale
                ? $item->sale
                : $sales->create($batch->user, [
                    'document_sector_code' => $batch->document_sector_code,
                    'sin_point_of_sale_id' => $batch->sin_point_of_sale_id,
                    'customer_id' => $batch->customer_id,
                    'issuance_key' => $issuanceKey,
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
            $item->update([
                'sale_id' => $item->sale_id ?? $sale->id,
                'stage' => 'ISSUING_OFFLINE',
                'message' => "Generando factura {$invoicePosition} de {$batch->invoices_per_cycle}.",
            ]);
            $result = $issuance->issueOfflineTest($sale);
            if (! $result->invoice || $result->invoice->fiscal_status !== InvoiceFiscalStatus::OfflineIssued) {
                $this->failCycle($result->message);

                return;
            }
            $item->update([
                'sin_invoice_issue_id' => $item->sin_invoice_issue_id ?? $result->invoice->id,
                'sin_significant_event_id' => $item->sin_significant_event_id ?? $result->invoice->sin_significant_event_id,
                'stage' => 'OFFLINE_ISSUED',
                'message' => "Factura {$invoicePosition} de {$batch->invoices_per_cycle} emitida fuera de línea.",
            ]);
            $item = $this->item();
        }

        $event = $item->significantEvent
            ?? throw new RuntimeException('La factura fuera de línea no creó su evento significativo.');
        if (! in_array($event->event_status, [SignificantEventStatus::Registered, SignificantEventStatus::Packaging,
            SignificantEventStatus::Sending, SignificantEventStatus::Validating, SignificantEventStatus::Completed], true)) {
            $item->update(['stage' => 'REGISTERING_EVENT']);
            $result = $recovery->prepareAndDetectRecovery(
                $event,
                $batch->user,
                (int) $batch->event_code,
                (string) $batch->event_description,
            );
            if (! $result->registered) {
                if ($result->event->manual_review_required || ! $result->retryable) {
                    $this->failCycle($result->message);

                    return;
                }
                $this->release(10);

                return;
            }
            $event = $result->event;
        }

        $package = $item->invoicePackage;
        if (! $package) {
            $package = $event->packages()->orderBy('id')->first();
        }
        if (! $package) {
            $item->update(['stage' => 'PACKAGING', 'message' => 'Esperando la generación del paquete de contingencia.']);
            $this->release(5);

            return;
        }
        $item->update(['sin_invoice_package_id' => $package->id]);

        if (in_array($package->package_status, [InvoicePackageStatus::Sent, InvoicePackageStatus::Rejected,
            InvoicePackageStatus::Observed, InvoicePackageStatus::Failed], true)) {
            $this->failCycle($package->message ?: 'SIAT no validó el paquete de contingencia.');

            return;
        }

        if (in_array($package->package_status, [InvoicePackageStatus::Created, InvoicePackageStatus::PendingSend], true)) {
            $item->update(['stage' => 'SENDING_PACKAGE', 'message' => 'Esperando el envío del paquete a SIAT.']);
            $this->release(5);

            return;
        }

        if ($package->package_status === InvoicePackageStatus::PendingValidation) {
            $item->update(['stage' => 'VALIDATING_PACKAGE', 'message' => 'Esperando la validación final del paquete.']);
            $this->release(10);

            return;
        }

        $event->refresh();
        if ($package->package_status !== InvoicePackageStatus::Validated
            || $event->event_status !== SignificantEventStatus::Completed) {
            $this->failCycle($package->message ?: 'El paquete no alcanzó una validación final satisfactoria.');

            return;
        }

        $item->update([
            'stage' => 'COMPLETED',
            'item_status' => InvoiceTestItemStatus::Succeeded,
            'message' => "{$batch->invoices_per_cycle} factura(s), evento y paquete validados correctamente.",
            'finished_at' => now(),
        ]);
        $this->advance();
    }

    public function failed(?Throwable $exception): void
    {
        $item = $this->item();
        $item->update([
            'stage' => 'FAILED',
            'item_status' => InvoiceTestItemStatus::Failed,
            'message' => $exception?->getMessage() ?: 'El ciclo de contingencia agotó sus reintentos.',
            'finished_at' => now(),
        ]);
        $this->advance();
    }

    private function advance(): void
    {
        $next = InvoiceTestBatchItem::query()->withoutGlobalScope('company')
            ->where('company_id', $this->companyId)->where('invoice_test_batch_id', $this->batchId)
            ->where('position', '>', $this->item()->position)->orderBy('position')->first();
        if ($next) {
            $this->refreshCounts();
            self::dispatch($this->companyId, $this->batchId, (int) $next->id);

            return;
        }
        $this->finishBatch(failed: false);
    }

    private function finishBatch(bool $failed): void
    {
        $this->refreshCounts($failed, true);
    }

    private function failCycle(string $message): void
    {
        $this->item()->update([
            'stage' => 'FAILED',
            'item_status' => InvoiceTestItemStatus::Failed,
            'message' => $message,
            'finished_at' => now(),
        ]);
        $this->advance();
    }

    private function refreshCounts(bool $forceFailed = false, bool $finished = false): void
    {
        DB::transaction(function () use ($forceFailed, $finished): void {
            $batch = InvoiceTestBatch::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($this->batchId);
            $items = InvoiceTestBatchItem::query()->withoutGlobalScope('company')->where('invoice_test_batch_id', $this->batchId);
            $successful = (clone $items)->where('item_status', InvoiceTestItemStatus::Succeeded)->count();
            $failed = (clone $items)->where('item_status', InvoiceTestItemStatus::Failed)->count();
            $batch->update([
                'processed_count' => $successful + $failed,
                'successful_count' => $successful,
                'failed_count' => $failed,
                'batch_status' => $finished
                    ? (($forceFailed || $failed > 0) ? InvoiceTestBatchStatus::CompletedWithErrors : InvoiceTestBatchStatus::Completed)
                    : InvoiceTestBatchStatus::Running,
                'finished_at' => $finished ? now() : null,
            ]);
        }, 3);
    }

    private function item(): InvoiceTestBatchItem
    {
        return InvoiceTestBatchItem::query()->withoutGlobalScope('company')
            ->with(['batch.user', 'batch.product', 'sale', 'invoice', 'significantEvent', 'invoicePackage'])
            ->where('company_id', $this->companyId)->where('invoice_test_batch_id', $this->batchId)
            ->findOrFail($this->itemId);
    }
}
