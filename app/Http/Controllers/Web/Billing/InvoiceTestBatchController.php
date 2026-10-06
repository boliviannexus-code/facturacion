<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Billing;

use App\Enums\InvoiceTestMode;
use App\Enums\SiatEnvironment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CancelInvoiceTestBatchRequest;
use App\Http\Requests\Billing\ReverseInvoiceTestBatchRequest;
use App\Http\Requests\Billing\StoreInvoiceTestBatchRequest;
use App\Jobs\CancelInvoiceTestItemJob;
use App\Jobs\IssueInvoiceTestItemJob;
use App\Jobs\ProcessOfflineContingencyTestItemJob;
use App\Jobs\ReverseInvoiceTestItemJob;
use App\Models\Customer;
use App\Models\InvoiceTestBatch;
use App\Models\Product;
use App\Models\SinAuthorization;
use App\Models\SinBranch;
use App\Models\SinCafcRange;
use App\Models\SinCatalogItem;
use App\Services\Billing\InvoiceDocumentSector;
use App\Services\Billing\InvoiceTestBatchService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

final class InvoiceTestBatchController extends Controller
{
    public function index(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;
        $selected = null;

        if ($request->filled('batch')) {
            $selected = InvoiceTestBatch::query()
                ->with(['items.invoice', 'items.significantEvent', 'items.invoicePackage', 'customer', 'product', 'pointOfSale.branch', 'user'])
                ->findOrFail((int) $request->integer('batch'));
        }

        $authorization = SinAuthorization::query()->where('company_id', $companyId)->first();
        $zeroRateActivityCodes = SinCatalogItem::query()
            ->where('catalog_key', 'actividades_documento_sector')
            ->active()
            ->get(['classifier_code', 'raw_data'])
            ->filter(fn (SinCatalogItem $item): bool => (int) data_get($item->raw_data, 'codigoDocumentoSector') === InvoiceDocumentSector::ZERO_RATE)
            ->map(fn (SinCatalogItem $item): string => (string) data_get($item->raw_data, 'codigoActividad', $item->classifier_code))
            ->unique()
            ->values();

        return view('billing.invoice-tests.index', [
            'pilotEnabled' => $authorization?->environment_code === SiatEnvironment::TestingAndPilot,
            'zeroRateActivityCodes' => $zeroRateActivityCodes,
            'supportedSectors' => InvoiceDocumentSector::supported(),
            'significantEvents' => SinCatalogItem::query()->where('catalog_key', 'eventos_significativos')
                ->active()->orderBy('classifier_code')->get(),
            'cafcRanges' => SinCafcRange::query()
                ->with(['branch', 'pointOfSale'])
                ->where('is_test_copy', false)
                ->orderBy('cafc_code')
                ->get(),
            'selectedBatch' => $selected,
            'batches' => InvoiceTestBatch::query()
                ->with(['customer', 'product', 'pointOfSale.branch'])
                ->latest('id')->paginate(10)->withQueryString(),
            'branches' => SinBranch::query()->with(['activePointsOfSale' => fn ($query) => $query->orderBy('point_of_sale_code')])
                ->where('is_active', true)->orderBy('branch_code')->get(),
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'name', 'document_number']),
            'products' => Product::query()->active()->whereNotNull('economic_activity_code')
                ->orderBy('description')->get(['id', 'description', 'internal_code', 'economic_activity_code', 'unit_price']),
            'activities' => SinCatalogItem::query()->where('catalog_key', 'actividades')
                ->active()->orderByRaw("raw_data->>'codigoCaeb'")->get(),
            'paymentMethods' => SinCatalogItem::query()->where('catalog_key', 'tipos_metodo_pago')
                ->active()->where('classifier_code', '!=', '2')->orderBy('classifier_code')->get(),
            'currencies' => SinCatalogItem::query()->where('catalog_key', 'tipos_moneda')
                ->active()->orderBy('classifier_code')->get(),
            'cancellationReasons' => SinCatalogItem::query()->where('catalog_key', 'motivos_anulacion')
                ->active()->orderBy('classifier_code')->get(),
        ]);
    }

    public function store(
        StoreInvoiceTestBatchRequest $request,
        InvoiceTestBatchService $service,
    ): RedirectResponse {
        $batch = $service->create($request->user(), $request->validated());
        if ($batch->test_mode === InvoiceTestMode::OfflineContingency) {
            $first = $batch->items->firstOrFail();
            ProcessOfflineContingencyTestItemJob::dispatch(
                (int) $batch->company_id,
                (int) $batch->id,
                (int) $first->id,
            );

            return redirect()->route('billing.invoice-tests.index', ['batch' => $batch->id])
                ->with('success', "Prueba de contingencia #{$batch->id} iniciada: {$batch->requested_count} ciclo(s), {$batch->invoices_per_cycle} factura(s) por ciclo.");
        }

        $jobs = $batch->items->map(fn ($item): IssueInvoiceTestItemJob => new IssueInvoiceTestItemJob(
            (int) $batch->company_id,
            (int) $batch->id,
            (int) $item->id,
        ))->all();

        Bus::chain($jobs)->onQueue('default')->dispatch();

        return redirect()->route('billing.invoice-tests.index', ['batch' => $batch->id])
            ->with('success', "Prueba #{$batch->id} iniciada. Las {$batch->requested_count} facturas se emitirán una por una.");
    }

    public function cancel(
        CancelInvoiceTestBatchRequest $request,
        InvoiceTestBatch $batch,
        InvoiceTestBatchService $service,
    ): RedirectResponse {
        abort_unless((int) $batch->company_id === (int) $request->user()->company_id, 404);
        $items = $service->prepareCancellation($batch, $request->integer('reason_code'));
        $jobs = $items->map(fn ($item): CancelInvoiceTestItemJob => new CancelInvoiceTestItemJob(
            (int) $batch->company_id, (int) $batch->id, (int) $item->id,
        ))->all();
        Bus::chain($jobs)->onQueue('default')->dispatch();

        return redirect()->route('billing.invoice-tests.index', ['batch' => $batch->id])
            ->with('success', "Anulación secuencial iniciada para {$items->count()} facturas del lote #{$batch->id}.");
    }

    public function reverse(
        ReverseInvoiceTestBatchRequest $request,
        InvoiceTestBatch $batch,
        InvoiceTestBatchService $service,
    ): RedirectResponse {
        abort_unless((int) $batch->company_id === (int) $request->user()->company_id, 404);
        $items = $service->prepareReversal($batch);
        $jobs = $items->map(fn ($item): ReverseInvoiceTestItemJob => new ReverseInvoiceTestItemJob(
            (int) $batch->company_id,
            (int) $batch->id,
            (int) $item->id,
        ))->all();
        Bus::chain($jobs)->onQueue('default')->dispatch();

        return redirect()->route('billing.invoice-tests.index', ['batch' => $batch->id])
            ->with('success', "Reversión secuencial iniciada para {$items->count()} facturas anuladas del lote #{$batch->id}.");
    }
}
