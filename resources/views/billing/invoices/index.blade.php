@extends('layouts.admin')

@section('title', 'Facturas | '.config('app.name', 'Base Admin'))
@section('page-title', 'Facturas')
@section('page-subtitle', 'Facturas emitidas y respuestas del SIN')

@section('content')
    <x-ui.table-card title="Facturas emitidas">
        <x-slot:actions>
            <div class="d-flex flex-column flex-lg-row gap-2 align-items-lg-end">
                <form class="d-flex flex-column flex-sm-row gap-2 align-items-sm-end" id="invoice-filters" autocomplete="off" data-datatable-filters>
                    <div>
                        <label class="form-label" for="invoice-status">Estado</label>
                        <select class="form-select form-select-sm" id="invoice-status" name="status">
                            <option value="">Todos</option>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}">{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn btn-outline-secondary btn-sm" type="reset">
                        <i class="ti ti-x me-1" aria-hidden="true"></i>Limpiar
                    </button>
                </form>

                @can('invoices.issue')
                    <a class="btn btn-success btn-sm" href="{{ route('billing.invoices.issue.index') }}">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>Emitir
                    </a>
                @endcan
            </div>
        </x-slot:actions>

        <table
            class="table table-hover align-middle mb-0 invoice-list-table"
            data-datatable
            data-url="{{ route('datatables.invoices') }}"
            data-order='[[2,"desc"]]'
            data-columns-id="invoice-table-columns"
            data-filters-form="#invoice-filters"
        >
            <thead>
                <tr>
                    <th>Factura</th>
                    <th>Tipo de factura</th>
                    <th>Fecha</th>
                    <th>Cliente</th>
                    <th class="text-end">Total</th>
                    <th>Estado SIN</th>
                    <th class="text-end">Accion</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>

        <script type="application/json" id="invoice-table-columns">
            [
                {"data":"invoice_number","name":"sin_invoice_issues.invoice_number"},
                {"data":"document_type","name":"sin_invoice_issues.document_sector_code","searchable":false},
                {"data":"issued_at","name":"sin_invoice_issues.issued_at"},
                {"data":"customer","name":"customers.name"},
                {"data":"total_amount","name":"sin_invoice_issues.total_amount","className":"text-end","searchable":false},
                {"data":"status_label","name":"sin_invoice_issues.fiscal_status"},
                {"data":"actions","name":"actions","className":"text-end","orderable":false,"searchable":false}
            ]
        </script>
    </x-ui.table-card>
@endsection
