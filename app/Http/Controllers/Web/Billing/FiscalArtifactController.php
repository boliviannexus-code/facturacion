<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Billing;

use App\Http\Controllers\Controller;
use App\Models\SinInvoiceIssue;
use App\Services\Billing\PurchaseSaleInvoicePdfService;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class FiscalArtifactController extends Controller
{
    public function xml(Request $request, SinInvoiceIssue $invoice): Response
    {
        $this->authorizeCompany($request, $invoice);
        abort_unless($invoice->xml_path && Storage::disk('local')->exists($invoice->xml_path), 404);

        return Storage::disk('local')->download($invoice->xml_path, 'factura-'.$invoice->cuf.'.xml', [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    public function viewXml(Request $request, SinInvoiceIssue $invoice): Response
    {
        $this->authorizeCompany($request, $invoice);
        abort_unless($invoice->xml_path && Storage::disk('local')->exists($invoice->xml_path), 404);

        return response(Storage::disk('local')->get($invoice->xml_path), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="factura-'.($invoice->invoice_number ?? $invoice->id).'.xml"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function pdf(Request $request, SinInvoiceIssue $invoice, PurchaseSaleInvoicePdfService $pdf): Response
    {
        $this->authorizeCompany($request, $invoice);
        abort_unless($invoice->payload && $invoice->cuf, 404);
        $contents = $invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path)
            ? Storage::disk('local')->get($invoice->pdf_path)
            : $pdf->render($invoice);

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="factura-'.($invoice->invoice_number ?? $invoice->attempted_invoice_number).'.pdf"',
        ]);
    }

    private function authorizeCompany(Request $request, SinInvoiceIssue $invoice): void
    {
        abort_unless(CompanyContext::belongsToUser((int) $invoice->company_id, $request->user()), 403);
    }
}
