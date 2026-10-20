@extends('layouts.admin')

@section('title', 'Revertir anulación | '.config('app.name'))
@section('page-title', 'Revertir anulación de factura N° '.$invoice->invoice_number)
@section('page-subtitle', 'Reversión individual ante el Servicio de Impuestos Nacionales')

@section('content')
<div class="row justify-content-center"><div class="col-xl-8">
    <div class="alert alert-warning" role="alert">
        Esta operación solo puede realizarse una vez, hasta el <strong>{{ $deadline->format('d/m/Y H:i') }}</strong>.
        Después de una reversión conforme, esta factura nunca podrá volver a anularse.
    </div>
    <div class="card"><div class="card-header"><h3 class="card-title">Datos de la reversión</h3></div><div class="card-body">
        <dl class="row">
            <dt class="col-sm-4">Código de autorización (CUF)</dt><dd class="col-sm-8 font-monospace text-break">{{ $invoice->cuf }}</dd>
            <dt class="col-sm-4">Comprador</dt><dd class="col-sm-8">{{ $invoice->customer?->name }}</dd>
            <dt class="col-sm-4">Correo de notificación</dt><dd class="col-sm-8">{{ $invoice->customer?->email ?: 'No registrado' }}</dd>
        </dl>
        <form method="POST" action="{{ route('billing.invoices.reversal', $invoice) }}" data-confirm-action
            data-confirm-title="¿Revertir la anulación de la factura N° {{ $invoice->invoice_number }}?"
            data-confirm-text="La factura volverá a ser válida y no podrá anularse nuevamente. El comprador será notificado."
            data-confirm-button="Sí, revertir en el SIN">
            @csrf
            <div class="mb-3"><label class="form-label" for="point_of_sale_id">Sucursal / punto de venta que realiza la reversión</label>
                <select class="form-select @error('point_of_sale_id') is-invalid @enderror" id="point_of_sale_id" name="point_of_sale_id" required>
                    <option value="">Seleccione</option>
                    @foreach($pointsOfSale as $point)<option value="{{ $point->id }}" @selected(old('point_of_sale_id', $invoice->cancellation_point_of_sale_id) == $point->id)>Sucursal {{ $point->branch->branch_code }} · {{ $point->display_name }}</option>@endforeach
                </select>@error('point_of_sale_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            @error('invoice')<div class="alert alert-danger">{{ $message }}</div>@enderror
            <div class="d-flex justify-content-end gap-2"><a class="btn btn-link" href="{{ route('billing.invoices.index') }}">Cancelar</a><button class="btn btn-success" type="submit">Revertir en el SIN</button></div>
        </form>
    </div></div>
</div></div>
@endsection
