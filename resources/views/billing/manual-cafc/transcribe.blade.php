@extends('layouts.admin')

@section('title', 'Transcribir factura manual | '.config('app.name'))
@section('page-title', 'Transcribir factura manual N.º '.$manual->manual_invoice_number)
@section('page-subtitle', 'Copie exactamente el contenido del documento físico')

@section('content')
    <form method="POST" enctype="multipart/form-data" action="{{ route('billing.manual-cafc.transcribe.update', $manual) }}" data-transcription-form>
        @csrf @method('PUT')
        <div class="row g-3">
            <div class="col-12 col-xl-4">
                <x-ui.card title="Identidad del documento">
                    <dl class="row mb-0">
                        <dt class="col-5 text-secondary">Empresa</dt><dd class="col-7">{{ auth()->user()->company->name }}</dd>
                        <dt class="col-5 text-secondary">CAFC</dt><dd class="col-7 fw-semibold">{{ $manual->cafcRange->cafc_code }}</dd>
                        <dt class="col-5 text-secondary">Número</dt><dd class="col-7 fw-semibold">{{ $manual->manual_invoice_number }}</dd>
                        <dt class="col-5 text-secondary">Sucursal</dt><dd class="col-7">{{ $manual->pointOfSale->branch->display_name }}</dd>
                        <dt class="col-5 text-secondary">Punto de venta</dt><dd class="col-7">{{ $manual->pointOfSale->display_name }}</dd>
                        <dt class="col-5 text-secondary">Fecha original</dt><dd class="col-7"><time datetime="{{ $manual->issued_manually_at->toIso8601String() }}">{{ $manual->issued_manually_at->format('d/m/Y H:i:s') }}</time></dd>
                        <dt class="col-5 text-secondary">Evento</dt><dd class="col-7">#{{ $manual->significantEvent?->id }} · {{ $manual->significantEvent?->event_description }}</dd>
                    </dl>
                    <div class="alert alert-warning mt-3 mb-0"><i class="ti ti-lock me-1" aria-hidden="true"></i>Número, CAFC y fecha original son inmutables.</div>
                </x-ui.card>
                <div class="mt-3">
                    <x-ui.card title="Cliente y respaldo">
                        <div class="mb-3">
                            <label class="form-label" for="customer_id">Cliente</label>
                            <select class="form-select @error('customer_id') is-invalid @enderror" id="customer_id" name="customer_id" required>
                                <option value="">Seleccione</option>
                                @foreach ($customers as $customer)
                                    <option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>{{ $customer->name }} · {{ $customer->document_number }}</option>
                                @endforeach
                            </select>
                            @error('customer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="row g-2">
                            <div class="col-6"><label class="form-label" for="payment_method_code">Método pago</label><input class="form-control" type="number" min="1" id="payment_method_code" name="payment_method_code" value="{{ old('payment_method_code', 1) }}" required></div>
                            <div class="col-6"><label class="form-label" for="currency_code">Moneda</label><input class="form-control" type="number" min="1" id="currency_code" name="currency_code" value="{{ old('currency_code', 1) }}" required></div>
                        </div>
                        <div class="mt-3"><label class="form-label" for="document_image">Imagen o PDF</label><input class="form-control" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" id="document_image" name="document_image"><div class="form-hint">Opcional. Máximo 10 MB.</div></div>
                        <div class="mt-3"><label class="form-label" for="observations">Observaciones</label><textarea class="form-control" id="observations" name="observations" rows="3" maxlength="2000">{{ old('observations') }}</textarea></div>
                    </x-ui.card>
                </div>
            </div>
            <div class="col-12 col-xl-8">
                <x-ui.table-card title="Productos o servicios">
                    <x-slot:actions><button class="btn btn-outline-primary btn-sm" type="button" data-add-line><i class="ti ti-plus me-1" aria-hidden="true"></i>Agregar línea</button></x-slot:actions>
                    <table class="table table-vcenter" data-items-table>
                        <thead><tr><th style="min-width:220px">Producto</th><th style="width:110px">Cantidad</th><th style="width:130px">Precio</th><th style="width:120px">Descuento</th><th class="text-end" style="width:130px">Subtotal</th><th class="w-1"><span class="visually-hidden">Quitar</span></th></tr></thead>
                        <tbody></tbody>
                    </table>
                </x-ui.table-card>
                <div class="card mt-3">
                    <div class="card-body">
                        <div class="row justify-content-end g-3 align-items-end">
                            <div class="col-12 col-sm-4"><label class="form-label" for="discount_amount">Descuento adicional</label><input class="form-control text-end" type="number" min="0" step="0.00001" id="discount_amount" name="discount_amount" value="{{ old('discount_amount', 0) }}" data-extra-discount></div>
                            <div class="col-12 col-sm-4"><label class="form-label" for="total_amount">Total físico</label><div class="input-group"><span class="input-group-text">Bs</span><input class="form-control text-end fw-bold" type="number" min="0" step="0.00001" id="total_amount" name="total_amount" value="{{ old('total_amount') }}" required data-total></div><div class="form-hint" data-calculated-total>Calculado: Bs 0.00</div></div>
                            <div class="col-12 col-sm-4 d-grid gap-2"><button class="btn btn-primary" type="submit"><i class="ti ti-file-code me-1" aria-hidden="true"></i>Transcribir y generar XML</button><a class="btn btn-link" href="{{ route('billing.manual-cafc.index') }}">Cancelar</a></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <template data-line-template>
        <tr data-line>
            <td><select class="form-select" data-name="product_id" required><option value="">Seleccione</option>@foreach($products as $product)<option value="{{ $product->id }}" data-price="{{ $product->unit_price }}">{{ $product->description }} · {{ $product->internal_code }}</option>@endforeach</select></td>
            <td><input class="form-control text-end" type="number" min="0.00001" step="0.00001" value="1" data-name="quantity" required></td>
            <td><input class="form-control text-end" type="number" min="0" step="0.00001" value="0" data-name="unit_price" required></td>
            <td><input class="form-control text-end" type="number" min="0" step="0.00001" value="0" data-name="discount_amount"></td>
            <td class="text-end fw-semibold" data-line-total>0.00</td>
            <td><button class="btn btn-icon btn-ghost-danger" type="button" data-remove-line aria-label="Quitar línea"><i class="ti ti-trash" aria-hidden="true"></i></button></td>
        </tr>
    </template>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const body = document.querySelector('[data-items-table] tbody');
    const template = document.querySelector('[data-line-template]');
    const extra = document.querySelector('[data-extra-discount]');
    const calculated = document.querySelector('[data-calculated-total]');
    let index = 0;
    const recalculate = () => {
        let subtotal = 0;
        body.querySelectorAll('[data-line]').forEach(row => {
            const quantity = Number(row.querySelector('[data-name="quantity"]').value || 0);
            const price = Number(row.querySelector('[data-name="unit_price"]').value || 0);
            const discount = Number(row.querySelector('[data-name="discount_amount"]').value || 0);
            const lineTotal = Math.max(0, quantity * price - discount);
            row.querySelector('[data-line-total]').textContent = lineTotal.toFixed(2);
            subtotal += lineTotal;
        });
        calculated.textContent = `Calculado: Bs ${Math.max(0, subtotal - Number(extra.value || 0)).toFixed(2)}`;
    };
    const addLine = () => {
        const fragment = template.content.cloneNode(true); const row = fragment.querySelector('[data-line]');
        row.querySelectorAll('[data-name]').forEach(input => input.name = `items[${index}][${input.dataset.name}]`);
        row.querySelector('[data-name="product_id"]').addEventListener('change', event => { const option = event.target.selectedOptions[0]; if (option?.dataset.price) row.querySelector('[data-name="unit_price"]').value = option.dataset.price; recalculate(); });
        row.addEventListener('input', recalculate);
        row.querySelector('[data-remove-line]').addEventListener('click', () => { if (body.children.length > 1) row.remove(); recalculate(); });
        body.appendChild(fragment); index++; recalculate();
    };
    document.querySelector('[data-add-line]').addEventListener('click', addLine); extra.addEventListener('input', recalculate); addLine();
});
</script>
@endpush
