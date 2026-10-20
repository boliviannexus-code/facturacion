<?php

namespace App\Http\Controllers\Web\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\RegisterPointOfSaleSignificantEventRequest;
use App\Http\Requests\Billing\RegisterSignificantEventRequest;
use App\Models\SinCatalogItem;
use App\Models\SinInvoiceIssue;
use App\Models\SinPointOfSale;
use App\Models\SinSignificantEvent;
use App\Services\Siat\SignificantEventService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SignificantEventController extends Controller
{
    public function __construct(private readonly SignificantEventService $events) {}

    public function index(Request $request): View
    {
        $points = $this->activePointsOfSale();
        $point = $points->firstWhere('id', $request->integer('point_of_sale_id')) ?? $points->first();

        return $this->form(null, $point, $points);
    }

    public function create(SinInvoiceIssue $invoice): View
    {
        abort_unless($invoice->allowsSignificantEvent(), 404);

        $invoice->load(['pointOfSale.branch', 'significantEvents' => fn ($query) => $query->latest()]);

        return $this->form($invoice, $invoice->pointOfSale, new Collection([$invoice->pointOfSale]));
    }

    public function createForPointOfSale(int $pointOfSale): View
    {
        $point = SinPointOfSale::query()->with('branch')->findOrFail($pointOfSale);

        return $this->form(null, $point, $this->activePointsOfSale());
    }

    public function store(RegisterSignificantEventRequest $request, SinInvoiceIssue $invoice): RedirectResponse
    {
        $event = $this->events->register($request->user(), $invoice, $request->validated());

        return redirect()
            ->route('billing.significant-events.create', $invoice)
            ->with($event->transaccion ? 'success' : 'error', $event->message);
    }

    public function storeForPointOfSale(RegisterPointOfSaleSignificantEventRequest $request): RedirectResponse
    {
        $point = SinPointOfSale::query()->with('branch')->findOrFail((int) $request->validated('sin_point_of_sale_id'));
        $event = $this->events->registerForPointOfSale($request->user(), $point, $request->validated());

        return redirect()
            ->route('billing.significant-events.index', ['point_of_sale_id' => $point->id])
            ->with($event->transaccion ? 'success' : 'error', $event->message);
    }

    /** @return Collection<int, SinPointOfSale> */
    private function activePointsOfSale(): Collection
    {
        return SinPointOfSale::query()
            ->with('branch')
            ->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query->where('is_active', true))
            ->orderBy('sin_branch_id')
            ->orderBy('point_of_sale_code')
            ->get();
    }

    /** @param Collection<int, SinPointOfSale> $points */
    private function form(?SinInvoiceIssue $invoice, ?SinPointOfSale $point, Collection $points): View
    {
        $pendingOfflineEvent = ! $invoice && $point
            ? $this->events->pendingOfflineEvent($point)
            : null;
        $registeredEvents = $invoice
            ? $invoice->significantEvents
            : ($point
                ? SinSignificantEvent::query()
                    ->whereNull('sin_invoice_issue_id')
                    ->where('sin_point_of_sale_id', $point->id)
                    ->latest()
                    ->limit(25)
                    ->get()
                : collect());

        return view('billing.significant-events.create', [
            'invoice' => $invoice,
            'pointOfSale' => $point,
            'pendingOfflineEvent' => $pendingOfflineEvent,
            'pointsOfSale' => $points,
            'registeredEvents' => $registeredEvents,
            'events' => SinCatalogItem::query()
                ->where('catalog_key', 'eventos_significativos')
                ->active()
                ->whereIn('classifier_code', ['1', '2', '3', '4'])
                ->orderByRaw("nullif(classifier_code, '')::integer nulls last")
                ->get(),
        ]);
    }
}
