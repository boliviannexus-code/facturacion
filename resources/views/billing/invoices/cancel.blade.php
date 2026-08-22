@extends('layouts.admin')

@section('title', 'Anular factura | '.config('app.name'))
@section('page-title', 'Anular factura N° '.$invoice->invoice_number)
@section('page-subtitle', 'Anulación individual ante el Servicio de Impuestos Nacionales')

@section('content')
    <div class="row justify-content-center">
        <div class="col-xl-8">
            <div class="alert alert-warning" role="alert">
                Esta operación fiscal se enviará al SIN. Plazo máximo: <strong>{{ $deadline->format('d/m/Y H:i') }}</strong>.
                El SIN rechazará la solicitud si el documento fue utilizado en una Declaración Jurada.
            </div>
            <div class="card">
                <div class="card-header"><h3 class="card-title">Datos de la anulación</h3></div>
                <div class="card-body">
                    <dl class="row">
                        <dt class="col-sm-4">Código de autorización (CUF)</dt><dd class="col-sm-8 font-monospace text-break">{{ $invoice->cuf }}</dd>
                        <dt class="col-sm-4">Comprador</dt><dd class="col-sm-8">{{ $invoice->customer?->name }}</dd>
                        <dt class="col-sm-4">Correo de notificación</dt><dd class="col-sm-8">{{ $invoice->customer?->email ?: 'No registrado' }}</dd>
                    </dl>
                    <form
                        method="POST"
                        action="{{ route('billing.invoices.cancel', $invoice) }}"
                        data-confirm-action
                        data-confirm-title="¿Anular la factura N° {{ $invoice->invoice_number }}?"
                        data-confirm-text="La solicitud será enviada al SIN y el comprador será notificado. Verifica el motivo antes de continuar."
                        data-confirm-button="Sí, anular en el SIN"
                    >
                        @csrf
                        <div class="mb-3">
                            <label class="form-label" for="point_of_sale_id">Sucursal / punto de venta que realiza la anulación</label>
                            <select class="form-select @error('point_of_sale_id') is-invalid @enderror" id="point_of_sale_id" name="point_of_sale_id" required>
                                <option value="">Seleccione</option>
                                @foreach($pointsOfSale as $point)<option value="{{ $point->id }}" @selected(old('point_of_sale_id', $invoice->sin_point_of_sale_id) == $point->id)>Sucursal {{ $point->branch->branch_code }} · {{ $point->display_name }}</option>@endforeach
                            </select>
                            @error('point_of_sale_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="reason_code">Motivo oficial de anulación</label>
                            <select class="form-select @error('reason_code') is-invalid @enderror" id="reason_code" name="reason_code" required>
                                <option value="">Seleccione</option>
                                @foreach($reasons as $reason)<option value="{{ $reason->classifier_code }}" @selected(old('reason_code') == $reason->classifier_code)>{{ $reason->classifier_code }} · {{ $reason->description }}</option>@endforeach
                            </select>
                            @error('reason_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        @error('invoice')<div class="alert alert-danger">{{ $message }}</div>@enderror
                        <div class="d-flex justify-content-end gap-2">
                            <a class="btn btn-link" href="{{ route('billing.invoices.index') }}">Cancelar</a>
                            <button class="btn btn-danger" type="submit">Anular en el SIN</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
