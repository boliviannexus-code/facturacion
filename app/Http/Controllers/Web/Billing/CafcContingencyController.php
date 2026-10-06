<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Billing;

use App\Enums\CafcRangeStatus;
use App\Enums\ManualContingencyInvoiceStatus;
use App\Enums\SiatEnvironment;
use App\Enums\SignificantEventStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\FinalizeCafcContingencyRequest;
use App\Http\Requests\Billing\StoreCafcContingencyInvoiceRequest;
use App\Http\Requests\Billing\StoreCafcContingencyRangeRequest;
use App\Http\Requests\Billing\UpdateCafcCodeRequest;
use App\Jobs\BuildContingencyPackagesJob;
use App\Models\SinAuthorization;
use App\Models\SinBranch;
use App\Models\SinCafcRange;
use App\Models\SinCatalogItem;
use App\Models\SinCufd;
use App\Models\SinPointOfSale;
use App\Services\Billing\InvoiceDocumentSector;
use App\Services\Billing\InvoicePackageService;
use App\Services\Billing\ManualCafcCompliance;
use App\Services\Billing\ManualCafcService;
use App\Services\Siat\SignificantEventService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'ranges' => SinCafcRange::query()->where('is_test_copy', false)->with(['branch', 'pointOfSale', 'significantEvent'])->latest()->paginate(15),
            'canRestartTests' => SinAuthorization::query()->first()?->environment_code === SiatEnvironment::TestingAndPilot,
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

        return redirect()->route('billing.cafc-contingencies.show', $range)->with('success', 'CAFC registrado. Primero registre el evento ante el SIN y luego transcriba las facturas.');
    }

    public function updateCode(UpdateCafcCodeRequest $request, SinCafcRange $cafcRange): RedirectResponse
    {
        $this->cafc->updateCode($cafcRange, (string) $request->validated('cafc_code'), $request->user());

        return back()->with('success', 'Código CAFC actualizado correctamente.');
    }

    public function show(SinCafcRange $cafcRange): View
    {
        abort_unless(InvoiceDocumentSector::supports((int) $cafcRange->document_sector_code), 422, 'El sector documental de este CAFC todavía no admite transcripción.');

        $cafcRange->load(['branch.activePointsOfSale', 'pointOfSale', 'significantEvent.cufd', 'significantEvent.recoveryCufd', 'manualInvoices.significantEvent', 'manualInvoices.customer']);

        return view('billing.cafc-contingencies.show', [
            'range' => $cafcRange,
            'eventClock' => now()->startOfSecond(),
            'eventCufds' => $cafcRange->significantEvent || ! $cafcRange->sin_point_of_sale_id
                ? collect()
                : SinCufd::query()->successful()
                    ->where('sin_point_of_sale_id', $cafcRange->sin_point_of_sale_id)
                    ->whereNotNull('requested_at')->whereNotNull('expires_at')
                    ->latest('requested_at')->get(),
            'canRestartTests' => SinAuthorization::query()->first()?->environment_code === SiatEnvironment::TestingAndPilot,
            'points' => $cafcRange->sin_point_of_sale_id
                ? collect([$cafcRange->pointOfSale])
                : $cafcRange->branch->activePointsOfSale,
            'canConsume' => in_array($cafcRange->range_status, [CafcRangeStatus::Available, CafcRangeStatus::InUse], true)
                && $cafcRange->significantEvent?->event_status === SignificantEventStatus::Registered
                && $cafcRange->significantEvent?->ended_at !== null
                && filled($cafcRange->significantEvent?->reception_code),
            'sectorTitle' => InvoiceDocumentSector::title((int) $cafcRange->document_sector_code),
            'events' => SinCatalogItem::query()->where('catalog_key', 'eventos_significativos')->active()->whereIn('classifier_code', ['5', '6', '7'])->orderBy('classifier_code')->get(),
        ]);
    }

    public function storeInvoice(StoreCafcContingencyInvoiceRequest $request, SinCafcRange $cafcRange): RedirectResponse
    {
        $data = $request->validated();
        $point = SinPointOfSale::query()->with('branch')->findOrFail((int) $data['sin_point_of_sale_id']);
        app(ManualCafcCompliance::class)->validate($cafcRange, $cafcRange->significantEvent, CarbonImmutable::parse($data['issued_manually_at']));
        $manual = $this->cafc->recordUsed(
            $cafcRange,
            $point,
            (int) $data['manual_invoice_number'],
            CarbonImmutable::parse($data['issued_manually_at']),
            $request->user(),
            $cafcRange->significantEvent,
        );

        return redirect()->route('billing.manual-cafc.transcribe.edit', $manual)->with(
            'success',
            'Número reservado en el CAFC. Transcribe la factura usando la interfaz de emisión.',
        );
    }

    public function registerEvent(FinalizeCafcContingencyRequest $request, SinCafcRange $cafcRange): RedirectResponse
    {
        $data = $request->validated();
        $event = DB::transaction(function () use ($request, $cafcRange, $data) {
            $locked = SinCafcRange::query()->lockForUpdate()->findOrFail($cafcRange->id);
            $locked->load(['pointOfSale', 'manualInvoices.invoice']);
            if ($locked->sin_significant_event_id !== null) {
                throw ValidationException::withMessages(['event_code' => 'Este CAFC ya tiene un evento registrado.']);
            }
            if (! $locked->pointOfSale) {
                throw ValidationException::withMessages(['sin_point_of_sale_id' => 'Asigne un punto de venta al CAFC antes de registrar el evento.']);
            }
            if ($locked->manualInvoices->contains(fn ($manual): bool => $manual->sin_invoice_issue_id !== null)) {
                throw ValidationException::withMessages(['event_code' => 'Este CAFC contiene transcripciones del flujo anterior. No se pueden asociar automáticamente a un nuevo evento ni modificar sus XML fiscales.']);
            }
            $startedAt = CarbonImmutable::parse($data['event_started_at']);
            $endedAt = CarbonImmutable::parse($data['event_ended_at']);
            if ($locked->manualInvoices->contains(fn ($manual): bool => $manual->issued_manually_at->lt($startedAt) || $manual->issued_manually_at->gt($endedAt))) {
                throw ValidationException::withMessages(['event_started_at' => 'El período debe incluir todas las fechas originales ya reservadas.']);
            }
            $event = $this->events->registerForPointOfSale($request->user(), $locked->pointOfSale, [
                'event_code' => (int) $data['event_code'], 'description' => $data['event_description'],
                'started_at' => $data['event_started_at'], 'ended_at' => $data['event_ended_at'],
            ]);
            if ($event->transaccion && filled($event->reception_code)) {
                $locked->forceFill(['sin_significant_event_id' => $event->id, 'updated_by_user_id' => $request->user()->id])->save();
                $locked->manualInvoices()->update(['sin_significant_event_id' => $event->id]);
            }

            return $event;
        });
        if (! $event->transaccion || blank($event->reception_code)) {
            throw ValidationException::withMessages(['event_code' => $event->message ?: 'El SIN no aceptó el evento. Corrija los datos antes de transcribir.']);
        }

        return back()->with('success', 'Evento registrado ante el SIN. Ahora transcriba las facturas dentro de su período; al terminar, finalice y envíe el CAFC.');
    }

    public function send(Request $request, SinCafcRange $cafcRange): RedirectResponse
    {
        DB::transaction(function () use ($request, $cafcRange): void {
            $range = SinCafcRange::query()->lockForUpdate()->findOrFail($cafcRange->id);
            $range->load(['significantEvent', 'manualInvoices.invoice']);
            $manuals = $range->manualInvoices->filter(fn ($manual): bool => $manual->manual_status !== ManualContingencyInvoiceStatus::Cancelled);
            if ($manuals->isEmpty() || $manuals->contains(fn ($manual): bool => ! $manual->invoice || $manual->manual_status === ManualContingencyInvoiceStatus::PendingTranscription)) {
                throw ValidationException::withMessages(['cafc_range_id' => 'Transcriba todas las facturas del CAFC antes de finalizar y enviar.']);
            }
            foreach ($manuals as $manual) {
                app(ManualCafcCompliance::class)->validateTranscription($manual);
            }
            // Close the range before dispatch so another request cannot add invoices to the package.
            $range->forceFill(['range_status' => CafcRangeStatus::Blocked, 'updated_by_user_id' => $request->user()->id])->save();
            foreach ($manuals as $manual) {
                app(InvoicePackageService::class)->validateManualInvoice($range->significantEvent, $manual->invoice);
            }
            BuildContingencyPackagesJob::dispatch((int) $range->company_id, (int) $range->sin_significant_event_id, (int) $request->user()->id)->afterCommit();
        });

        return back()->with('success', 'CAFC finalizado. Generación y envío de paquetes encolados.');
    }
}
