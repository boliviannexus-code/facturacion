<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\CafcRangeStatus;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\InvoiceTestBatchStatus;
use App\Enums\InvoiceTestItemStatus;
use App\Enums\InvoiceTestMode;
use App\Enums\SiatEnvironment;
use App\Models\InvoiceTestBatch;
use App\Models\InvoiceTestBatchItem;
use App\Models\Product;
use App\Models\SinAuthorization;
use App\Models\SinCafcRange;
use App\Models\SinCatalogItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvoiceTestBatchService
{
    public function __construct(
        private readonly InvoiceCancellationReversalService $reversals,
    ) {}

    /** @return Collection<int, InvoiceTestBatchItem> */
    public function prepareCancellation(InvoiceTestBatch $batch, int $reasonCode): Collection
    {
        $this->assertPilotEnvironment((int) $batch->company_id);

        return DB::transaction(function () use ($batch, $reasonCode): Collection {
            $locked = InvoiceTestBatch::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($batch->id);
            if ($locked->cancellation_status?->isActive()) {
                throw ValidationException::withMessages(['cancellation' => 'La anulación de este lote ya está en ejecución.']);
            }

            $items = $locked->items()->withoutGlobalScope('company')
                ->where('item_status', InvoiceTestItemStatus::Succeeded)
                ->whereHas('invoice', fn ($query) => $query->whereIn('fiscal_status', [
                    InvoiceFiscalStatus::Validated,
                    InvoiceFiscalStatus::ValidatedAfterContingency,
                ]))
                ->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['cancellation' => 'El lote no tiene facturas validadas pendientes de anulación.']);
            }

            $locked->update(['cancellation_status' => InvoiceTestBatchStatus::Pending,
                'cancellation_reason_code' => $reasonCode, 'cancellation_requested_count' => $items->count(),
                'cancellation_processed_count' => 0, 'cancellation_successful_count' => 0,
                'cancellation_failed_count' => 0, 'cancellation_started_at' => null, 'cancellation_finished_at' => null]);
            $items->each->update(['cancellation_status' => InvoiceTestItemStatus::Pending,
                'cancellation_message' => null, 'cancellation_started_at' => null, 'cancellation_finished_at' => null]);

            return $items;
        }, 3);
    }

    /** @return Collection<int, InvoiceTestBatchItem> */
    public function prepareReversal(InvoiceTestBatch $batch): Collection
    {
        $this->assertPilotEnvironment((int) $batch->company_id);

        return DB::transaction(function () use ($batch): Collection {
            $locked = InvoiceTestBatch::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($batch->id);
            if ($locked->reversal_status?->isActive()) {
                throw ValidationException::withMessages(['reversal' => 'La reversión de este lote ya está en ejecución.']);
            }

            $items = $locked->items()->withoutGlobalScope('company')
                ->with('invoice.customer')
                ->where('cancellation_status', InvoiceTestItemStatus::Succeeded)
                ->whereHas('invoice', fn ($query) => $query
                    ->where('fiscal_status', InvoiceFiscalStatus::CancelledInSiat)
                    ->where('cancellation_status_code', 905)
                    ->whereNull('reversed_at')
                    ->whereHas('customer', fn ($customer) => $customer->whereNotNull('email')->where('email', '!=', '')))
                ->get()
                ->filter(fn (InvoiceTestBatchItem $item): bool => now()->lte($this->reversals->deadline($item->invoice)))
                ->values();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'reversal' => 'El lote no tiene facturas anuladas que cumplan las condiciones para reversión.',
                ]);
            }

            $locked->update([
                'reversal_status' => InvoiceTestBatchStatus::Pending,
                'reversal_requested_count' => $items->count(),
                'reversal_processed_count' => 0,
                'reversal_successful_count' => 0,
                'reversal_failed_count' => 0,
                'reversal_started_at' => null,
                'reversal_finished_at' => null,
            ]);
            $items->each->update([
                'reversal_status' => InvoiceTestItemStatus::Pending,
                'reversal_message' => null,
                'reversal_started_at' => null,
                'reversal_finished_at' => null,
            ]);

            return $items;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): InvoiceTestBatch
    {
        $companyId = (int) $user->company_id;
        $this->assertPilotEnvironment($companyId);

        $product = Product::query()->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->findOrFail((int) $data['product_id']);

        if (! $product->economic_activity_code) {
            throw ValidationException::withMessages([
                'product_id' => 'El producto necesita una actividad económica homologada para la prueba.',
            ]);
        }

        if ((int) $product->economic_activity_code !== (int) $data['economic_activity_code']) {
            throw ValidationException::withMessages([
                'product_id' => 'El producto no pertenece a la actividad económica seleccionada.',
            ]);
        }

        $mode = InvoiceTestMode::from((string) ($data['test_mode'] ?? InvoiceTestMode::Online->value));
        $manualCafc = $mode === InvoiceTestMode::OfflineContingency
            && in_array((int) ($data['event_code'] ?? 0), [5, 6, 7], true);
        $count = (int) $data['invoice_count'];
        $invoicesPerCycle = $mode === InvoiceTestMode::OfflineContingency ? (int) ($data['invoices_per_cycle'] ?? 1) : 1;
        $maximumCount = $mode === InvoiceTestMode::OfflineContingency ? 10 : 500;
        $eventDescription = null;

        if ($mode === InvoiceTestMode::OfflineContingency) {
            $eventDescription = SinCatalogItem::query()->withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('catalog_key', 'eventos_significativos')
                ->where('classifier_code', (string) $data['event_code'])
                ->where('is_active', true)
                ->value('description');

            if (! filled($eventDescription)) {
                throw ValidationException::withMessages([
                    'event_code' => 'El evento seleccionado no tiene una descripción oficial vigente del SIN.',
                ]);
            }
        }

        $sourceCafc = null;
        if ($manualCafc) {
            $range = SinCafcRange::query()->withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->whereKey((int) ($data['sin_cafc_range_id'] ?? 0))
                ->where('sin_point_of_sale_id', (int) $data['sin_point_of_sale_id'])
                ->where('is_test_copy', false)
                ->first();
            if (! $range || ($range->range_end - $range->range_start + 1) < $invoicesPerCycle) {
                throw ValidationException::withMessages([
                    'sin_cafc_range_id' => 'El CAFC debe pertenecer al punto de venta y tener un rango suficiente.',
                ]);
            }
            $sourceCafc = $range;
        }

        if ($count < 1 || $count > $maximumCount) {
            throw ValidationException::withMessages([
                'invoice_count' => $mode === InvoiceTestMode::OfflineContingency
                    ? 'La prueba de contingencia admite entre 1 y 10 ciclos.'
                    : 'La prueba en línea admite entre 1 y 500 facturas.',
            ]);
        }
        if ($invoicesPerCycle < 1 || $invoicesPerCycle > 500) {
            throw ValidationException::withMessages([
                'invoices_per_cycle' => 'Cada ciclo admite entre 1 y 500 facturas.',
            ]);
        }

        return DB::transaction(function () use ($user, $data, $companyId, $product, $mode, $manualCafc, $sourceCafc, $count, $invoicesPerCycle, $eventDescription): InvoiceTestBatch {
            $active = InvoiceTestBatch::query()->withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where(function ($query): void {
                    $query->whereIn('batch_status', [InvoiceTestBatchStatus::Pending, InvoiceTestBatchStatus::Running])
                        ->orWhereIn('cancellation_status', [InvoiceTestBatchStatus::Pending, InvoiceTestBatchStatus::Running])
                        ->orWhereIn('reversal_status', [InvoiceTestBatchStatus::Pending, InvoiceTestBatchStatus::Running]);
                })
                ->lockForUpdate()
                ->exists();

            if ($active) {
                throw ValidationException::withMessages([
                    'invoice_count' => 'Ya existe una prueba en ejecución. Espera a que termine antes de iniciar otra.',
                ]);
            }

            $testCafcs = collect();
            if ($manualCafc) {
                $testCafcs = collect(range(1, $count))->map(fn (int $cycle): SinCafcRange => SinCafcRange::query()
                    ->withoutGlobalScope('company')->create([
                        ...$sourceCafc->only(['company_id', 'sin_branch_id', 'sin_point_of_sale_id', 'cafc_code', 'document_sector_code', 'range_start', 'range_end', 'authorized_from', 'authorized_until']),
                        'source_sin_cafc_range_id' => $sourceCafc->id, 'is_test_copy' => true,
                        'created_by_user_id' => $user->id, 'updated_by_user_id' => $user->id,
                        'next_number' => $sourceCafc->range_start, 'range_status' => CafcRangeStatus::Available,
                        'used_count' => 0, 'cancelled_count' => 0,
                        'notes' => "Copia aislada para ciclo de prueba {$cycle}",
                    ]));
            }
            $batch = InvoiceTestBatch::query()->create([
                'company_id' => $companyId,
                'user_id' => $user->id,
                'sin_point_of_sale_id' => (int) $data['sin_point_of_sale_id'],
                'sin_cafc_range_id' => $testCafcs->first()?->id,
                'customer_id' => (int) $data['customer_id'],
                'product_id' => $product->id,
                'batch_key' => (string) Str::uuid(),
                'batch_status' => InvoiceTestBatchStatus::Pending,
                'test_mode' => $mode,
                'requested_count' => $count,
                'invoices_per_cycle' => $invoicesPerCycle,
                'document_sector_code' => (int) $data['document_sector_code'],
                'event_code' => $mode === InvoiceTestMode::OfflineContingency ? (int) $data['event_code'] : null,
                'event_description' => $eventDescription,
                'economic_activity_code' => (int) $data['economic_activity_code'],
                'payment_method_code' => (int) $data['payment_method_code'],
                'currency_code' => (int) $data['currency_code'],
                'quantity' => $data['quantity'],
                'unit_price' => $data['unit_price'],
            ]);

            $batch->items()->createMany(collect(range(1, $batch->requested_count))
                ->map(fn (int $position): array => [
                    'company_id' => $companyId,
                    'position' => $position,
                    'sin_cafc_range_id' => $testCafcs->get($position - 1)?->id,
                    'issuance_key' => (string) Str::uuid(),
                ])->all());

            return $batch->load(['items', 'product', 'customer', 'pointOfSale.branch']);
        }, 3);
    }

    public function assertPilotEnvironment(int $companyId): void
    {
        $environment = SinAuthorization::query()->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->value('environment_code');

        $environment = $environment instanceof SiatEnvironment
            ? $environment
            : SiatEnvironment::tryFrom((int) $environment);

        if ($environment !== SiatEnvironment::TestingAndPilot) {
            throw ValidationException::withMessages([
                'environment' => 'Las pruebas masivas están bloqueadas fuera del ambiente Piloto del SIN.',
            ]);
        }
    }

}
