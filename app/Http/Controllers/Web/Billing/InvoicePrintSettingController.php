<?php

namespace App\Http\Controllers\Web\Billing;

use App\Enums\InvoicePrintFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\SaveInvoicePrintSettingsRequest;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class InvoicePrintSettingController extends Controller
{
    public function edit(): View
    {
        $company = CompanyContext::activeCompany();

        abort_unless($company, 404);

        return view('billing.settings.print', [
            'company' => $company,
            'formats' => InvoicePrintFormat::options(),
            'selectedFormat' => InvoicePrintFormat::fromValue($company->invoice_print_format)->value,
        ]);
    }

    public function update(SaveInvoicePrintSettingsRequest $request): RedirectResponse
    {
        $company = CompanyContext::activeCompany($request->user());

        abort_unless($company, 404);

        $company->update($request->validated());

        return redirect()
            ->route('billing.invoice-print-settings.edit')
            ->with('success', 'Configuracion de impresion actualizada correctamente.');
    }
}
