<?php

namespace App\Http\Controllers\Web;

use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use App\Http\Controllers\Controller;
use App\Models\SinCatalogItem;
use App\Models\SinInvoiceIssue;
use App\Models\User;
use App\Services\Billing\InvoiceDocumentSector;
use App\Services\Billing\PurchaseSaleInvoicePdfService;
use App\Services\Siat\SiatCatalogRegistry;
use App\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OwenIt\Auditing\Models\Audit;
use Yajra\DataTables\Facades\DataTables;

class AdminDataTableController extends Controller
{
    public function __construct(
        private readonly PurchaseSaleInvoicePdfService $invoicePdf,
    ) {}

    public function audits(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('audits.view'), 403);

        $query = Audit::query()
            ->from('audits')
            ->select('audits.*', 'users.name as user_name', 'companies.name as company_name')
            ->leftJoin('users', function ($join): void {
                $join->on('users.id', '=', 'audits.user_id')
                    ->where('audits.user_type', User::class);
            })
            ->leftJoin('companies', 'companies.id', '=', 'audits.company_id')
            ->when(CompanyContext::id(), fn ($query, $companyId) => $query->where('audits.company_id', $companyId))
            ->when($request->filled('company_id'), fn ($query) => $query->where('audits.company_id', $request->integer('company_id')))
            ->when($request->filled('user_id'), fn ($query) => $query->where('audits.user_id', $request->integer('user_id'))->where('audits.user_type', User::class))
            ->when($request->filled('event'), fn ($query) => $query->where('audits.event', $request->string('event')))
            ->when($request->filled('auditable_type'), fn ($query) => $query->where('audits.auditable_type', $request->string('auditable_type')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('audits.created_at', '>=', $request->date('date_from')->toDateString()))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('audits.created_at', '<=', $request->date('date_to')->toDateString()));

        return DataTables::eloquent($query)
            ->editColumn('created_at', fn (Audit $audit): string => $audit->created_at?->format('Y-m-d H:i:s') ?? '')
            ->addColumn('company_name', fn (Audit $audit): string => $audit->company_name ?: 'Global')
            ->addColumn('user_name', fn (Audit $audit): string => $audit->user_name ?: 'Sistema')
            ->editColumn('event', fn (Audit $audit): string => $this->auditEventBadge((string) $audit->event))
            ->addColumn('auditable_label', fn (Audit $audit): string => AuditController::auditableLabel((string) $audit->auditable_type))
            ->addColumn('record_id', fn (Audit $audit): string => (string) $audit->auditable_id)
            ->addColumn('changes', fn (Audit $audit): string => $this->auditChangesSummary($audit))
            ->addColumn('actions', fn (Audit $audit): string => $this->auditActions((int) $audit->id))
            ->rawColumns(['event', 'actions'])
            ->toJson();
    }

    public function siatCatalogItems(Request $request, string $catalog): JsonResponse
    {
        abort_unless(auth()->user()?->can('siat-catalogs.view'), 403);

        app(SiatCatalogRegistry::class)->find($catalog);

        $query = SinCatalogItem::query()
            ->where('catalog_key', $catalog)
            ->select('sin_catalog_items.*');

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $keyword = trim((string) data_get($request->input('search'), 'value', ''));

                if ($keyword === '') {
                    return;
                }

                $query->where(function ($query) use ($keyword): void {
                    $query->where('classifier_code', 'ilike', "%{$keyword}%")
                        ->orWhere('item_key', 'ilike', "%{$keyword}%")
                        ->orWhere('description', 'ilike', "%{$keyword}%")
                        ->orWhereRaw('raw_data::text ilike ?', ["%{$keyword}%"]);
                });
            })
            ->addColumn('selector', fn (SinCatalogItem $item): string => $this->catalogItemSelector($item))
            ->addColumn('status', fn (SinCatalogItem $item): string => $this->catalogItemStatus($catalog, $item))
            ->addColumn('code', fn (SinCatalogItem $item): string => e($item->classifier_code ?: $item->item_key ?: '-'))
            ->editColumn('description', fn (SinCatalogItem $item): string => e($item->description ?: '-'))
            ->addColumn('raw_fields', fn (SinCatalogItem $item): string => $this->catalogRawFields($item))
            ->editColumn('synced_at', fn (SinCatalogItem $item): string => $item->synced_at?->format('Y-m-d H:i:s') ?? '')
            ->addColumn('json', fn (SinCatalogItem $item): string => $this->catalogJson($item))
            ->rawColumns(['selector', 'status', 'raw_fields', 'json'])
            ->toJson();
    }

    public function invoices(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('invoices.view'), 403);

        $query = SinInvoiceIssue::query()
            ->withoutGlobalScope('company')
            ->select(
                'sin_invoice_issues.*',
                'customers.name as customer_name',
                'customers.document_number as customer_document_number'
            )
            ->leftJoin('customers', 'customers.id', '=', 'sin_invoice_issues.customer_id')
            ->when(CompanyContext::id(), fn ($query, $companyId) => $query->where('sin_invoice_issues.company_id', $companyId));

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                if ($request->filled('status')) {
                    $query->where('sin_invoice_issues.fiscal_status', $request->string('status'));
                }

                $keyword = trim((string) data_get($request->input('search'), 'value', ''));

                if ($keyword === '') {
                    return;
                }

                $query->where(function ($query) use ($keyword): void {
                    $query
                        ->where('sin_invoice_issues.cuf', 'ilike', "%{$keyword}%")
                        ->orWhere('sin_invoice_issues.reception_code', 'ilike', "%{$keyword}%")
                        ->orWhere('sin_invoice_issues.status_label', 'ilike', "%{$keyword}%")
                        ->orWhere('sin_invoice_issues.fiscal_status', 'ilike', "%{$keyword}%")
                        ->orWhere('customers.name', 'ilike', "%{$keyword}%")
                        ->orWhere('customers.document_number', 'ilike', "%{$keyword}%");

                    if (is_numeric($keyword)) {
                        $query
                            ->orWhere('sin_invoice_issues.invoice_number', (int) $keyword)
                            ->orWhere('sin_invoice_issues.attempted_invoice_number', (int) $keyword);
                    }
                });
            })
            ->editColumn('invoice_number', fn (SinInvoiceIssue $invoice): string => $this->invoiceNumberColumn($invoice))
            ->addColumn('document_type', fn (SinInvoiceIssue $invoice): string => $this->invoiceDocumentTypeColumn($invoice))
            ->editColumn('issued_at', fn (SinInvoiceIssue $invoice): string => $invoice->issued_at?->format('d/m/Y H:i') ?? '-')
            ->addColumn('customer', fn (SinInvoiceIssue $invoice): string => $this->invoiceCustomerColumn($invoice))
            ->editColumn('total_amount', fn (SinInvoiceIssue $invoice): string => 'Bs '.money_format_decimal($invoice->total_amount))
            ->editColumn('status_label', fn (SinInvoiceIssue $invoice): string => $this->invoiceStatusColumn($invoice))
            ->addColumn('actions', fn (SinInvoiceIssue $invoice): string => $this->invoiceActions($invoice))
            ->rawColumns(['invoice_number', 'document_type', 'customer', 'status_label', 'actions'])
            ->toJson();
    }

    private function auditEventBadge(string $event): string
    {
        $tone = match ($event) {
            'created' => 'success',
            'updated' => 'primary',
            'deleted' => 'danger',
            'restored' => 'info',
            default => 'secondary',
        };

        return '<span class="badge text-bg-'.$tone.'">'.AuditController::eventLabel($event).'</span>';
    }

    private function invoiceNumberColumn(SinInvoiceIssue $invoice): string
    {
        if ($invoice->invoice_number) {
            $number = '<div class="fw-semibold">Nro. '.e((string) $invoice->invoice_number).'</div>';
        } else {
            $number = '<div class="fw-semibold text-body-secondary">Intento nro. '.e((string) ($invoice->attempted_invoice_number ?? '-')).'</div>'
                .'<div class="text-danger small">No validada</div>';
        }

        return $number.'<div class="text-body-secondary small">Suc. '
            .e((string) $invoice->branch_code)
            .' / PV '
            .e((string) $invoice->point_of_sale_code)
            .'</div>';
    }

    private function invoiceCustomerColumn(SinInvoiceIssue $invoice): string
    {
        return '<div>'.e((string) ($invoice->customer_name ?? '-')).'</div>'
            .'<div class="text-body-secondary small">'
            .e((string) ($invoice->customer_document_number ?? 'Sin documento'))
            .'</div>';
    }

    private function invoiceDocumentTypeColumn(SinInvoiceIssue $invoice): string
    {
        return match ((int) $invoice->document_sector_code) {
            InvoiceDocumentSector::PURCHASE_SALE => '<span class="badge bg-blue-lt">'
                .'<i class="ti ti-receipt-2 me-1" aria-hidden="true"></i>Compra-Venta</span>'
                .'<div class="text-body-secondary small">Sector 1</div>',
            InvoiceDocumentSector::ZERO_RATE => '<span class="badge bg-purple-lt">'
                .'<i class="ti ti-percentage me-1" aria-hidden="true"></i>Tasa Cero</span>'
                .'<div class="text-body-secondary small">Sector 8 · Sin crédito fiscal</div>',
            default => '<span class="badge bg-secondary-lt">Documento sector '
                .e((string) $invoice->document_sector_code).'</span>',
        };
    }

    private function invoiceStatusColumn(SinInvoiceIssue $invoice): string
    {
        $statusTone = match ($invoice->fiscal_status) {
            InvoiceFiscalStatus::Validated,
            InvoiceFiscalStatus::ValidatedAfterContingency,
            InvoiceFiscalStatus::ManualValidated => 'bg-success-lt',
            InvoiceFiscalStatus::Observed,
            InvoiceFiscalStatus::UncertainSend => 'bg-yellow-lt',
            InvoiceFiscalStatus::PendingOnlineSend,
            InvoiceFiscalStatus::PendingPackage,
            InvoiceFiscalStatus::PackageSent => 'bg-blue-lt',
            default => 'bg-danger-lt',
        };

        $status = '<span class="badge '.$statusTone.'">'.e($invoice->fiscal_status->label()).'</span>';

        if ($invoice->failure_category) {
            $status .= '<div class="text-body-secondary small">'.e($invoice->failure_category->label()).'</div>';
        }

        if ($invoice->status_code) {
            $status .= '<div class="text-body-secondary small">Codigo '.e((string) $invoice->status_code).'</div>';
        }

        return $status;
    }

    private function invoiceActions(SinInvoiceIssue $invoice): string
    {
        $xmlAction = $invoice->xml_path
            ? '<a class="btn btn-outline-secondary btn-sm" href="'.e(route('billing.invoices.xml', $invoice)).'" target="_blank" rel="noopener">'
                .'<i class="ti ti-file-code me-1" aria-hidden="true"></i>Ver XML</a>'
            : '';

        if (auth()->user()?->can('invoices.issue')
            && $invoice->fiscal_status === InvoiceFiscalStatus::PendingOnlineSend
            && $invoice->emission_mode === InvoiceEmissionMode::Online) {
            return '<form class="d-inline" method="POST" data-disable-on-submit action="'.e(route('billing.invoices.resend', $invoice)).'">'
                .csrf_field().'<button class="btn btn-outline-primary btn-sm" type="submit" data-submitting-label="Encolando…">'
                .'<i class="ti ti-send me-1" aria-hidden="true"></i><span>Reenviar al SIN</span></button></form>'
                .($xmlAction !== '' ? ' '.$xmlAction : '');
        }

        if (auth()->user()?->can('invoices.issue')
            && in_array($invoice->fiscal_status, [InvoiceFiscalStatus::Observed, InvoiceFiscalStatus::Rejected], true)
            && in_array($invoice->status_code, [904, 902], true)) {
            return '<a class="btn btn-outline-warning btn-sm" href="'.e(route('billing.invoices.payment.correct.form', $invoice)).'">'
                .'<i class="ti ti-edit me-1" aria-hidden="true"></i>Corregir método de pago</a>'
                .($xmlAction !== '' ? ' '.$xmlAction : '');
        }

        $registeredInSiat = ($invoice->status_code === 908 && $invoice->transaccion && $invoice->invoice_number)
            || in_array($invoice->fiscal_status, [InvoiceFiscalStatus::CancelledInSiat, InvoiceFiscalStatus::ReversedInSiat], true);
        $printableOffline = $invoice->emission_mode === InvoiceEmissionMode::OfflineDigital
            && $invoice->invoice_number
            && filled($invoice->cuf)
            && filled($invoice->payload);

        if (! $registeredInSiat && ! $printableOffline) {
            return $xmlAction !== '' ? $xmlAction : '<span class="text-body-secondary small">-</span>';
        }

        $actions = '<a class="btn btn-outline-primary btn-sm" href="'
            .e(route('billing.invoices.print', $invoice))
            .'" target="_blank" rel="noopener">'
            .'<i class="ti ti-printer me-1" aria-hidden="true"></i>Reimprimir'
            .'</a>'
            .($xmlAction !== '' ? ' '.$xmlAction : '');

        if ($registeredInSiat) {
            $actions .= ' <a class="btn btn-outline-info btn-sm" href="'
                .e($this->invoicePdf->verificationUrl($invoice))
                .'" target="_blank" rel="noopener noreferrer">'
                .'<i class="ti ti-shield-check me-1" aria-hidden="true"></i>Verificar factura</a>';
        }

        if (auth()->user()?->can('invoices.cancel')
            && in_array($invoice->fiscal_status, [InvoiceFiscalStatus::Validated, InvoiceFiscalStatus::ValidatedAfterContingency, InvoiceFiscalStatus::ManualValidated], true)) {
            $actions .= ' <a class="btn btn-outline-danger btn-sm" href="'.e(route('billing.invoices.cancel.form', $invoice)).'">'
                .'<i class="ti ti-file-off me-1" aria-hidden="true"></i>Anular</a>';
        }

        if (auth()->user()?->can('invoices.cancel') && $invoice->fiscal_status === InvoiceFiscalStatus::CancelledInSiat) {
            $actions .= ' <a class="btn btn-outline-success btn-sm" href="'.e(route('billing.invoices.reversal.form', $invoice)).'">'
                .'<i class="ti ti-arrow-back-up me-1" aria-hidden="true"></i>Revertir anulación</a>';
        }

        return $actions;
    }

    private function auditChangesSummary(Audit $audit): string
    {
        $old = array_keys($audit->old_values ?? []);
        $new = array_keys($audit->new_values ?? []);
        $fields = array_values(array_unique(array_merge($old, $new)));

        if ($fields === []) {
            return '-';
        }

        return collect($fields)
            ->take(4)
            ->implode(', ')
            .(count($fields) > 4 ? '...' : '');
    }

    private function auditActions(int $auditId): string
    {
        $url = route('audits.show', $auditId);

        return '<a class="btn btn-outline-primary btn-sm" href="'.$url.'" data-modal-url="'.$url.'" data-modal-title="Detalle de auditoria">Ver</a>';
    }

    private function catalogItemSelector(SinCatalogItem $item): string
    {
        return '<input class="form-check-input" form="catalog-selected-status-form" name="items[]" type="checkbox" value="'
            .e((string) $item->id)
            .'" aria-label="Seleccionar item '
            .e($item->classifier_code ?: $item->item_key)
            .'" data-catalog-item-selector>';
    }

    private function catalogItemStatus(string $catalog, SinCatalogItem $item): string
    {
        $buttonClass = $item->is_active ? 'btn-success' : 'btn-outline-secondary';
        $label = $item->is_active ? 'Activo' : 'Inactivo';

        return '<form method="POST" action="'
            .e(route('siat.catalogs.items.update-status', [$catalog, $item]))
            .'">'
            .csrf_field()
            .method_field('PATCH')
            .'<input type="hidden" name="is_active" value="'.($item->is_active ? '0' : '1').'">'
            .'<button class="btn btn-sm '.$buttonClass.'" type="submit">'.$label.'</button>'
            .'</form>';
    }

    private function catalogRawFields(SinCatalogItem $item): string
    {
        $fields = collect($item->raw_data ?? [])
            ->filter(fn ($value): bool => is_scalar($value) && trim((string) $value) !== '');

        if ($fields->isEmpty()) {
            return '<span class="text-body-secondary">-</span>';
        }

        return $fields
            ->map(fn ($value, string|int $key): string => '<div class="small"><span class="text-body-secondary">'
                .e((string) $key)
                .':</span> <span class="fw-semibold">'
                .e((string) $value)
                .'</span></div>')
            ->implode('');
    }

    private function catalogJson(SinCatalogItem $item): string
    {
        $json = json_encode($item->raw_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return '<details><summary class="text-primary">Ver</summary><pre class="mt-2 mb-0 p-2 bg-muted-lt rounded text-secondary small">'
            .e((string) $json)
            .'</pre></details>';
    }
}
