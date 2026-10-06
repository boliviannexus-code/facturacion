<?php

namespace App\Http\Controllers\Web\Billing;

use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CancelInvoiceRequest;
use App\Http\Requests\Billing\CorrectInvoicePaymentRequest;
use App\Http\Requests\Billing\ReverseInvoiceCancellationRequest;
use App\Jobs\ResendPendingOnlineInvoiceJob;
use App\Models\SinCatalogItem;
use App\Models\SinInvoiceIssue;
use App\Models\SinPointOfSale;
use App\Services\Billing\InvoiceCancellationReversalService;
use App\Services\Billing\InvoiceCancellationService;
use App\Services\Billing\InvoiceIssuanceService;
use App\Services\Billing\PurchaseSaleInvoicePdfService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function index(): View
    {
        return view('billing.invoices.index', [
            'statuses' => InvoiceFiscalStatus::cases(),
        ]);
    }

    public function print(SinInvoiceIssue $invoice, PurchaseSaleInvoicePdfService $pdf): Response
    {
        $number = $invoice->invoice_number ?? $invoice->attempted_invoice_number ?? $invoice->id;
        $contents = $pdf->render($invoice);

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="factura-'.$number.'.pdf"',
        ]);
    }

    public function cancelForm(SinInvoiceIssue $invoice, InvoiceCancellationService $service): View
    {
        abort_unless($invoice->company_id === auth()->user()?->company_id, 404);

        return view('billing.invoices.cancel', [
            'invoice' => $invoice->loadMissing('customer'),
            'deadline' => $service->deadline($invoice),
            'reasons' => SinCatalogItem::query()->where('catalog_key', 'motivos_anulacion')->active()->orderBy('classifier_code')->get(),
            'pointsOfSale' => SinPointOfSale::query()->with('branch')->where('is_active', true)->orderBy('point_of_sale_code')->get(),
        ]);
    }

    public function cancel(CancelInvoiceRequest $request, SinInvoiceIssue $invoice, InvoiceCancellationService $service): RedirectResponse
    {
        $cancelled = $service->cancel($invoice, $request->integer('point_of_sale_id'), $request->integer('reason_code'), $request->user());
        $message = $cancelled->fiscal_status === InvoiceFiscalStatus::CancelledInSiat
            ? (filled($cancelled->customer?->email)
                ? 'Factura anulada correctamente en el SIN y notificación programada.'
                : 'Factura anulada correctamente en el SIN; el cliente no tiene correo registrado.')
            : 'El SIN no confirmó la anulación: '.$cancelled->cancellation_message;

        return redirect()->route('billing.invoices.index')->with($cancelled->fiscal_status === InvoiceFiscalStatus::CancelledInSiat ? 'success' : 'error', $message);
    }

    public function reversalForm(SinInvoiceIssue $invoice, InvoiceCancellationReversalService $service): View
    {
        abort_unless($invoice->company_id === auth()->user()?->company_id, 404);

        return view('billing.invoices.reversal', [
            'invoice' => $invoice->loadMissing('customer'), 'deadline' => $service->deadline($invoice),
            'pointsOfSale' => SinPointOfSale::query()->with('branch')->where('is_active', true)->orderBy('point_of_sale_code')->get(),
        ]);
    }

    public function reverseCancellation(ReverseInvoiceCancellationRequest $request, SinInvoiceIssue $invoice, InvoiceCancellationReversalService $service): RedirectResponse
    {
        $reversed = $service->reverse($invoice, $request->integer('point_of_sale_id'), $request->user());
        $success = $reversed->fiscal_status === InvoiceFiscalStatus::ReversedInSiat;
        $message = $success
            ? (filled($reversed->customer?->email)
                ? 'Anulación revertida correctamente y notificación programada.'
                : 'Anulación revertida correctamente; el cliente no tiene correo registrado.')
            : 'El SIN no confirmó la reversión: '.$reversed->reversal_message;

        return redirect()->route('billing.invoices.index')->with($success ? 'success' : 'error', $message);
    }

    public function correctPaymentForm(SinInvoiceIssue $invoice): View
    {
        abort_unless($invoice->company_id === auth()->user()?->company_id, 404);

        return view('billing.invoices.correct-payment', [
            'invoice' => $invoice->loadMissing('sale.items'),
            'paymentMethods' => SinCatalogItem::query()->where('catalog_key', 'tipos_metodo_pago')->active()->orderByRaw("nullif(classifier_code, '')::integer nulls last")->get(),
        ]);
    }

    public function correctPayment(CorrectInvoicePaymentRequest $request, SinInvoiceIssue $invoice, InvoiceIssuanceService $service): RedirectResponse
    {
        $result = $service->correctPaymentAndResend(
            $invoice,
            $request->integer('payment_method_code'),
            $request->string('card_number')->toString() ?: null,
            $request->user(),
            $request->validated(),
        );
        $success = $result->invoice?->fiscal_status === InvoiceFiscalStatus::Validated;

        return redirect()->route('billing.invoices.index')->with($success ? 'success' : 'error', $result->message);
    }

    public function resendPendingOnline(SinInvoiceIssue $invoice): RedirectResponse
    {
        abort_unless($invoice->company_id === auth()->user()?->company_id, 404);
        abort_unless(
            $invoice->fiscal_status === InvoiceFiscalStatus::PendingOnlineSend
            && $invoice->emission_mode === InvoiceEmissionMode::Online,
            422,
        );

        $lockKey = 'siat:invoice-resend:'.$invoice->company_id.':'.$invoice->id;
        if (! Cache::add($lockKey, true, now()->addMinutes(5))) {
            return redirect()->route('billing.invoices.index')->with(
                'info',
                'Esta factura ya tiene un reenvío en cola. Espere el resultado antes de intentar nuevamente.',
            );
        }

        ResendPendingOnlineInvoiceJob::dispatch(
            (int) $invoice->company_id,
            (int) $invoice->id,
            (int) auth()->id(),
        );

        return redirect()->route('billing.invoices.index')->with(
            'success',
            'Reenvío encolado. Se usará la misma factura, número y CUF; actualice el listado en unos instantes.',
        );
    }
}
