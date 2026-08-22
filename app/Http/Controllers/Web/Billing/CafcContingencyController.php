<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Billing;

use App\Enums\CafcRangeStatus;
use App\Enums\ManualContingencyInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\FinalizeCafcContingencyRequest;
use App\Http\Requests\Billing\StoreCafcContingencyInvoiceRequest;
use App\Http\Requests\Billing\StoreCafcContingencyRangeRequest;
use App\Jobs\BuildContingencyPackagesJob;
use App\Models\SinBranch;
use App\Models\SinCafcRange;
use App\Models\SinCatalogItem;
use App\Models\SinPointOfSale;
use App\Services\Billing\InvoiceDocumentSector;
use App\Services\Billing\ManualCafcService;
use App\Services\Siat\SignificantEventService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CafcContingencyController extends Controller
{
    public function __construct(
        private readonly ManualCafcService $cafc,
        private readonly SignificantEventService $events,
    ) {}

    public function index(): View
    {
        return view('billing.cafc-contingencies.index', [
            'ranges' => SinCafcRange::query()->with(['branch', 'pointOfSale', 'significantEvent'])->latest()->paginate(15),
            'branches' => SinBranch::query()->with('activePointsOfSale')->where('is_active', true)->orderBy('branch_code')->get(),
            'sectors' => SinCatalogItem::query()
                ->where('catalog_key', 'tipos_documento_sector')
                ->whereIn('classifier_code', [
                    (string) InvoiceDocumentSector::PURCHASE_SALE,
                    (string) InvoiceDocumentSector::ZERO_RATE,
                ])
                ->active()
                ->orderByRaw("nullif(classifier_code, '')::integer nulls last")
                ->get(),
        ]);
    }

    public function storeRange(StoreCafcContingencyRangeRequest $request): RedirectResponse
    {
        $range = $this->cafc->registerRange($request->validated(), $request->user());

        return redirect()->route('billing.cafc-contingencies.show', $range)->with('success', 'CAFC registrado. Ahora transcribe todas las facturas y finaliza registrando el evento.');
    }

    public function show(SinCafcRange $cafcRange): View
    {
        abort_unless(InvoiceDocumentSector::supports((int) $cafcRange->document_sector_code), 422, 'El sector documental de este CAFC todavía no admite transcripción.');

        $cafcRange->load(['branch.activePointsOfSale', 'pointOfSale', 'significantEvent', 'manualInvoices.significantEvent', 'manualInvoices.customer']);

        return view('billing.cafc-contingencies.show', [
            'range' => $cafcRange,
            'points' => $cafcRange->sin_point_of_sale_id
                ? collect([$cafcRange->pointOfSale])
                : $cafcRange->branch->activePointsOfSale,
            'canConsume' => in_array($cafcRange->range_status, [CafcRangeStatus::Available, CafcRangeStatus::InUse], true),
            'sectorTitle' => InvoiceDocumentSector::title((int) $cafcRange->document_sector_code),
            'events' => SinCatalogItem::query()->where('catalog_key', 'eventos_significativos')->active()->whereIn('classifier_code', ['5', '6', '7'])->orderBy('classifier_code')->get(),
        ]);
    }

    public function storeInvoice(StoreCafcContingencyInvoiceRequest $request, SinCafcRange $cafcRange): RedirectResponse
    {
        $data = $request->validated();
        $point = SinPointOfSale::query()->with('branch')->findOrFail((int) $data['sin_point_of_sale_id']);
        if ($cafcRange->sin_significant_event_id !== null) {
            throw ValidationException::withMessages(['cafc_range_id' => 'El evento de este CAFC ya fue registrado; no se pueden agregar más facturas.']);
        }
        $manual = $this->cafc->recordUsed(
            $cafcRange,
            $point,
            (int) $data['manual_invoice_number'],
            CarbonImmutable::parse($data['issued_manually_at']),
            $request->user(),
            null,
        );

        return redirect()->route('billing.manual-cafc.transcribe.edit', $manual)->with(
            'success',
            'Número reservado en el CAFC. Transcribe la factura usando la interfaz de emisión.',
        );
    }

    public function finalize(FinalizeCafcContingencyRequest $request, SinCafcRange $cafcRange): RedirectResponse
    {
        $cafcRange->load(['pointOfSale', 'manualInvoices.invoice']);

        if ($cafcRange->sin_significant_event_id !== null) {
            throw ValidationException::withMessages(['event_code' => 'Este CAFC ya tiene un evento significativo registrado.']);
        }
        if ($cafcRange->manualInvoices->isEmpty()) {
            throw ValidationException::withMessages(['event_code' => 'Transcribe al menos una factura antes de registrar el evento.']);
        }
        if ($cafcRange->manualInvoices->contains(fn ($manual): bool => $manual->manual_status === ManualContingencyInvoiceStatus::PendingTranscription)) {
            throw ValidationException::withMessages(['event_code' => 'Todas las facturas registradas deben estar transcritas antes de finalizar el CAFC.']);
        }

        $data = $request->validated();
        $startedAt = CarbonImmutable::parse($data['event_started_at']);
        $endedAt = CarbonImmutable::parse($data['event_ended_at']);
        if ($cafcRange->manualInvoices->contains(fn ($manual): bool => $manual->issued_manually_at->lt($startedAt) || $manual->issued_manually_at->gt($endedAt))) {
            throw ValidationException::withMessages(['event_started_at' => 'El periodo del evento debe incluir las fechas originales de todas las facturas del CAFC.']);
        }

        $event = $this->events->registerForPointOfSale($request->user(), $cafcRange->pointOfSale, [
            'event_code' => (int) $data['event_code'],
            'description' => $data['event_description'],
            'started_at' => $data['event_started_at'],
            'ended_at' => $data['event_ended_at'],
        ]);

        if (! $event->transaccion) {
            throw ValidationException::withMessages(['event_code' => $event->message ?: 'El SIN no aceptó el evento significativo. Puedes corregir los datos e intentarlo nuevamente.']);
        }

        DB::transaction(function () use ($cafcRange, $event, $request): void {
            $locked = SinCafcRange::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($cafcRange->id);
            if ($locked->sin_significant_event_id !== null) {
                throw ValidationException::withMessages(['event_code' => 'El CAFC fue finalizado por otro usuario.']);
            }

            $locked->forceFill(['sin_significant_event_id' => $event->id, 'updated_by_user_id' => $request->user()->id])->save();
            $locked->manualInvoices()->update(['sin_significant_event_id' => $event->id]);
            $locked->manualInvoices()->whereNotNull('sin_invoice_issue_id')->each(function ($manual) use ($event): void {
                $manual->invoice()->update(['sin_significant_event_id' => $event->id]);
            });
        }, 3);

        BuildContingencyPackagesJob::dispatch(
            (int) $cafcRange->company_id,
            (int) $event->id,
            (int) $request->user()->id,
        );

        return back()->with('success', 'Evento registrado ante el SIN. Las facturas CAFC serán enviadas mediante un paquete de contingencia.');
    }
}
