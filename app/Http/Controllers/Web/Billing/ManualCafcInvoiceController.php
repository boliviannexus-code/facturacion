<?php

namespace App\Http\Controllers\Web\Billing;

use App\Enums\CafcRangeStatus;
use App\Enums\ManualContingencyInvoiceStatus;
use App\Enums\SignificantEventStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\RecordManualCafcInvoiceRequest;
use App\Http\Requests\Billing\TranscribeManualCafcInvoiceRequest;
use App\Jobs\BuildContingencyPackagesJob;
use App\Models\Customer;
use App\Models\SinCafcRange;
use App\Models\SinManualContingencyInvoice;
use App\Models\SinPointOfSale;
use App\Models\SinSignificantEvent;
use App\Services\Billing\InvoiceDocumentSector;
use App\Services\Billing\ManualCafcService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class ManualCafcInvoiceController extends Controller
{
    public function __construct(private readonly ManualCafcService $cafc) {}

    public function index(): View
    {
        return view('billing.manual-cafc.index', [
            'manualInvoices' => SinManualContingencyInvoice::query()->with(['cafcRange', 'branch', 'pointOfSale', 'transcriber'])->latest('issued_manually_at')->paginate(15),
            'ranges' => SinCafcRange::query()->with(['branch', 'pointOfSale'])
                ->whereIn('range_status', [CafcRangeStatus::Available, CafcRangeStatus::InUse])->orderBy('authorized_until')->get(),
            'points' => SinPointOfSale::query()->with('branch')->where('is_active', true)->orderBy('sin_branch_id')->orderBy('point_of_sale_code')->get(),
            'events' => SinSignificantEvent::query()->whereNull('closed_at')->latest('started_at')->get(),
        ]);
    }

    public function store(RecordManualCafcInvoiceRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $range = SinCafcRange::query()->findOrFail($data['cafc_range_id']);
        $point = SinPointOfSale::query()->findOrFail($data['sin_point_of_sale_id']);
        $event = SinSignificantEvent::query()->findOrFail($data['significant_event_id']);
        $issuedAt = CarbonImmutable::parse($data['issued_manually_at']);

        $manual = $data['operation'] === 'cancelled'
            ? $this->cafc->recordCancelled($range, $point, (int) $data['manual_invoice_number'], $issuedAt, $request->user(), $data['void_reason'], $event)
            : $this->cafc->recordUsed($range, $point, (int) $data['manual_invoice_number'], $issuedAt, $request->user(), $event);

        return $manual->manual_status === ManualContingencyInvoiceStatus::Cancelled
            ? back()->with('success', 'Número físico anulado y bloqueado para reutilización.')
            : redirect()->route('billing.manual-cafc.transcribe.edit', $manual)->with('success', 'Número físico registrado. Complete la transcripción.');
    }

    public function edit(SinManualContingencyInvoice $manualInvoice): View
    {
        abort_unless($manualInvoice->manual_status === ManualContingencyInvoiceStatus::PendingTranscription, 404);
        abort_unless(InvoiceDocumentSector::supports((int) $manualInvoice->document_sector_code), 422, 'El sector documental de este CAFC todavía no admite transcripción.');

        $manual = $manualInvoice->load(['cafcRange', 'pointOfSale.branch', 'significantEvent']);
        $normalForm = app(InvoiceIssueController::class)->show((string) $manual->document_sector_code);

        return view('billing.invoices.issue.purchase-sale', [
            ...$normalForm->getData(),
            'manualCafc' => $manual,
        ]);
    }

    public function update(TranscribeManualCafcInvoiceRequest $request, SinManualContingencyInvoice $manualInvoice): RedirectResponse|JsonResponse
    {
        $customer = Customer::query()->findOrFail((int) $request->validated('customer_id'));
        $manual = $this->cafc->transcribe($manualInvoice, $customer, $request->safe()->except(['items', 'document_image']), $request->validated('items'), $request->user(), $request->file('document_image'));
        $rangeHasPendingTranscriptions = $manual->cafcRange()->whereHas(
            'manualInvoices',
            fn ($query) => $query->where('manual_status', ManualContingencyInvoiceStatus::PendingTranscription->value),
        )->exists();
        if (! $rangeHasPendingTranscriptions && in_array($manual->significantEvent?->event_status, [SignificantEventStatus::Registered, SignificantEventStatus::Packaging, SignificantEventStatus::Sending, SignificantEventStatus::Validating], true)) {
            BuildContingencyPackagesJob::dispatch((int) $manual->company_id, (int) $manual->sin_significant_event_id, (int) $request->user()->id);
        }

        $message = 'Factura transcrita y XML guardado. El envío iniciará cuando el evento esté registrado ante el SIAT.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'redirect_url' => route('billing.cafc-contingencies.show', $manual->sin_cafc_range_id),
                'data' => ['invoice' => [
                    'id' => $manual->invoice?->id,
                    'invoice_number' => $manual->manual_invoice_number,
                    'status_label' => $manual->manual_status->label(),
                    'print_url' => $manual->invoice ? route('billing.invoices.print', $manual->invoice) : null,
                ]],
            ], 201);
        }

        return redirect()->route('billing.cafc-contingencies.show', $manual->sin_cafc_range_id)->with('success', $message);
    }

    public function send(SinManualContingencyInvoice $manualInvoice): RedirectResponse
    {
        $manualInvoice->loadMissing(['invoice', 'significantEvent']);
        if (! $manualInvoice->significantEvent || (int) $manualInvoice->invoice?->emission_type_code !== 2) {
            throw ValidationException::withMessages(['manual_invoice' => 'La factura no tiene un evento registrado o fue generada con el flujo anterior y requiere corrección técnica.']);
        }

        BuildContingencyPackagesJob::dispatch((int) $manualInvoice->company_id, (int) $manualInvoice->sin_significant_event_id, (int) request()->user()->id);

        return back()->with('success', 'Generación y envío del paquete CAFC encolados.');
    }
}
