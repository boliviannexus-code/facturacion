<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CafcRangeStatus;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\InvoicePackageStatus;
use App\Enums\InvoiceTestBatchStatus;
use App\Enums\InvoiceTestItemStatus;
use App\Enums\SignificantEventStatus;
use App\Models\InvoiceTestBatch;
use App\Models\InvoiceTestBatchItem;
use App\Models\SinCafcRange;
use App\Models\SinInvoiceIssue;
use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\InvoicePackageService;
use App\Services\Billing\InvoiceTestBatchService;
use App\Services\Billing\ManualCafcService;
use App\Services\Billing\SaleCreationService;
use App\Services\Billing\SequentialCafcTestPeriod;
use App\Services\Siat\ContingencyRecoveryService;
use App\Services\Siat\SignificantEventService;
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
        ManualCafcService $manualCafc,
        SignificantEventService $significantEvents,
        InvoicePackageService $packages,
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

        if (in_array((int) $batch->event_code, [5, 6, 7], true)) {
            $this->handleManualCafc($item, $manualCafc, $significantEvents, $packages);

            return;
        }

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

    private function handleManualCafc(
        InvoiceTestBatchItem $item,
        ManualCafcService $manualCafc,
        SignificantEventService $significantEvents,
        InvoicePackageService $packages,
    ): void {
        $batch = $item->batch;
        $range = $item->cafcRange ?? $batch->cafcRange
            ?? throw new RuntimeException('La prueba de eventos 5, 6 o 7 requiere un CAFC.');
        $point = $batch->pointOfSale()->with('branch')->firstOrFail();

        if (! $item->significantEvent) {
            $period = app(SequentialCafcTestPeriod::class)->prepare($point, (int) $batch->invoices_per_cycle);
            if ($period['wait'] > 0) {
                $item->update([
                    'stage' => 'WAITING_CUFD_INTERVAL',
                    'message' => 'Esperando un intervalo posterior al CUFD para iniciar el siguiente ciclo CAFC.',
                ]);
                $this->release($period['wait']);

                return;
            }
            $event = $significantEvents->registerForPointOfSale($batch->user, $point, [
                'event_code' => (int) $batch->event_code, 'description' => (string) $batch->event_description,
                'started_at' => $period['start']->toDateTimeString(),
                'ended_at' => $period['end']->toDateTimeString(),
            ]);
            if (! $event->transaccion || blank($event->reception_code)) {
                $this->failCycle($event->message ?: 'SIAT no aceptó el evento de prueba CAFC.');

                return;
            }
            DB::transaction(function () use ($range, $event, $item): void {
                $range->forceFill(['sin_significant_event_id' => $event->id])->save();
                $item->update(['sin_significant_event_id' => $event->id, 'stage' => 'TRANSCRIBING']);
            }, 3);
            $item = $this->item();
        }
        $event = $item->significantEvent;
        $manualInvoices = $range->manualInvoices()->orderBy('manual_invoice_number')->get();
        if ($manualInvoices->count() > $batch->invoices_per_cycle) {
            throw new RuntimeException('El CAFC contiene más facturas que las solicitadas por la prueba.');
        }
        for ($position = 1; $position <= $batch->invoices_per_cycle; $position++) {
            $manual = $manualInvoices->get($position - 1);
            if (! $manual) {
                $range = SinCafcRange::query()->withoutGlobalScope('company')->findOrFail($range->id);
                $manual = $manualCafc->recordUsed($range, $point, (int) $range->next_number,
                    $event->ended_at->subSeconds($batch->invoices_per_cycle - $position + 1), $batch->user, $event);
            }
            if ($manual->sin_invoice_issue_id === null) {
                $manualCafc->transcribe($manual, $batch->customer, [
                    'payment_method_code' => $batch->payment_method_code, 'currency_code' => $batch->currency_code,
                    'discount_amount' => 0, 'total_amount' => (float) $batch->quantity * (float) $batch->unit_price,
                    'observations' => "Prueba automática CAFC #{$batch->id}",
                ], [[
                    'product_id' => $batch->product_id, 'quantity' => $batch->quantity,
                    'unit_price' => $batch->unit_price, 'discount_amount' => 0,
                ]], $batch->user);
            }
        }
        if ($range->range_status->canConsume() || $range->range_status === CafcRangeStatus::Exhausted) {
            $range->forceFill(['range_status' => CafcRangeStatus::Blocked])->save();
        }
        $item->update([
            'sin_invoice_issue_id' => $range->manualInvoices()->orderBy('manual_invoice_number')->first()?->sin_invoice_issue_id,
            'stage' => 'PACKAGING', 'message' => 'Evento registrado y facturas CAFC transcritas; generando paquete.',
        ]);

        $event = $item->significantEvent;
        $package = $item->invoicePackage ?? $event?->packages()->orderBy('id')->first();
        if (! $package) {
            $package = $packages->buildForEvent($event, $batch->user)->first();
        }
        if (! $package) {
            $this->failCycle('No fue posible generar el paquete CAFC de prueba.');

            return;
        }
        $item->update(['sin_invoice_package_id' => $package->id]);

        if (in_array($package->package_status, [InvoicePackageStatus::Created, InvoicePackageStatus::PendingSend], true)) {
            $package = $packages->send($package, $batch->user)->package;
        }
        if ($package->package_status === InvoicePackageStatus::PendingValidation) {
            $result = $packages->checkValidation($package, $batch->user);
            $package = $result->package;
            if ($result->pending) {
                $item->update(['stage' => 'VALIDATING_PACKAGE', 'message' => 'Esperando la validación final del paquete CAFC.']);
                $this->release(10);

                return;
            }
        }

        if ($package->package_status !== InvoicePackageStatus::Validated) {
            $this->failCycle($package->message ?: 'SIAT no validó el paquete CAFC de prueba.');

            return;
        }

        $item->update([
            'stage' => 'COMPLETED',
            'item_status' => InvoiceTestItemStatus::Succeeded,
            'message' => "{$batch->invoices_per_cycle} factura(s) CAFC, evento y paquete validados correctamente.",
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
            ->with(['batch.user', 'batch.product', 'batch.customer', 'batch.cafcRange', 'cafcRange', 'sale', 'invoice', 'significantEvent', 'invoicePackage'])
            ->where('company_id', $this->companyId)->where('invoice_test_batch_id', $this->batchId)
            ->findOrFail($this->itemId);
    }
}
