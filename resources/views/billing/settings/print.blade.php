@extends('layouts.admin')

@section('title', 'Configuracion de impresion | '.config('app.name', 'Base Admin'))
@section('page-title', 'Configuracion de impresion')
@section('page-subtitle', 'Formato predeterminado para facturas de '.$company->name)

@section('content')
    <x-ui.form-panel :action="route('billing.invoice-print-settings.update')" method="PUT">
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label">Formato por defecto</label>
                <div class="row g-3">
                    @foreach ($formats as $value => $label)
                        <div class="col-md-6">
                            <label class="form-selectgroup-item flex-fill">
                                <input
                                    class="form-selectgroup-input"
                                    name="invoice_print_format"
                                    type="radio"
                                    value="{{ $value }}"
                                    @checked(old('invoice_print_format', $selectedFormat) === $value)
                                >
                                <span class="form-selectgroup-label d-flex align-items-center p-3">
                                    <span class="me-3">
                                        <span class="form-selectgroup-check"></span>
                                    </span>
                                    <span>
                                        <span class="d-block fw-semibold">{{ $label }}</span>
                                        <span class="d-block text-body-secondary small">
                                            {{ $value === \App\Enums\InvoicePrintFormat::Roll->value ? 'Ticket de 80 mm para impresora termica.' : 'Hoja vertical para impresion de media hoja.' }}
                                        </span>
                                    </span>
                                </span>
                            </label>
                        </div>
                    @endforeach
                </div>
                @error('invoice_print_format')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end mt-4">
            <a class="btn btn-outline-secondary" href="{{ route('billing.invoices.index') }}">
                <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Volver
            </a>
            <button class="btn btn-primary" type="submit">
                <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Guardar configuracion
            </button>
        </div>
    </x-ui.form-panel>
@endsection
