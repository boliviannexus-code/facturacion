@extends('layouts.admin')

@section('title', 'Corregir método de pago | '.config('app.name'))
@section('page-title', 'Corregir factura N° '.$invoice->invoice_number)
@section('page-subtitle', 'Regeneración y reenvío de la misma factura al SIN')

@section('content')
<div class="row justify-content-center"><div class="col-xl-7"><div class="card"><div class="card-body">
    <div class="alert alert-warning">Se conservarán el número de factura y CUF. El XML será regenerado, validado, comprimido y reenviado al SIN.</div>
    <form method="POST" action="{{ route('billing.invoices.payment.correct', $invoice) }}" data-confirm-action
        data-confirm-title="¿Corregir y reenviar la factura?" data-confirm-text="Se enviará nuevamente la misma factura con el método de pago corregido."
        data-confirm-button="Sí, corregir y reenviar">
        @csrf
        <label class="form-label" for="payment_method_code">Método de pago vigente</label>
        <select class="form-select @error('payment_method_code') is-invalid @enderror" id="payment_method_code" name="payment_method_code" required data-payment-method>
            @foreach($paymentMethods as $method)
                @php($normalizedMethod = preg_replace('/[^a-z0-9]+/', '', strtolower(\Illuminate\Support\Str::ascii((string) $method->description))))
                @php($isGiftCardMethod = str_contains($normalizedMethod, 'gift') || str_contains($normalizedMethod, 'tarjetaregalo'))
                <option value="{{ $method->classifier_code }}" data-is-gift-card="{{ $isGiftCardMethod ? '1' : '0' }}" @selected(old('payment_method_code', $invoice->sale?->payment_method_code) == $method->classifier_code)>{{ $method->classifier_code }} · {{ $method->description }}</option>
            @endforeach
        </select>
        @error('payment_method_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="mt-3 {{ old('payment_method_code', $invoice->sale?->payment_method_code) == 2 ? '' : 'd-none' }}" data-payment-card>
            <label class="form-label" for="card_number">Número de tarjeta</label>
            <input class="form-control" id="card_number" name="card_number" inputmode="numeric" autocomplete="off" maxlength="19" placeholder="1234 5678 9012 3456">
            <div class="form-text">El número completo no se almacena; se convierte al formato 1234000000003456.</div>
        </div>
        @error('card_number')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        <h3 class="h4 mt-4">Importes y descuentos</h3>
        <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Ítem</th><th>Cantidad</th><th>Precio</th><th>Tipo descuento</th><th>Valor</th></tr></thead><tbody>
        @foreach($invoice->sale->items as $index => $item)
            <tr><td>{{ $item->description }}<input type="hidden" name="items[{{ $index }}][id]" value="{{ $item->id }}"></td>
                <td><input class="form-control form-control-sm" name="items[{{ $index }}][quantity]" type="number" min="0.00001" step="0.00001" value="{{ $item->quantity }}" required></td>
                <td><input class="form-control form-control-sm" name="items[{{ $index }}][unit_price]" type="number" min="0" step="0.01" value="{{ $item->unit_price }}" required></td>
                <td><select class="form-select form-select-sm" name="items[{{ $index }}][discount_type]" data-correction-discount-type><option value="FIXED" @selected($item->discount_type === 'FIXED')>Importe</option><option value="PERCENTAGE" @selected($item->discount_type === 'PERCENTAGE')>Porcentaje</option></select></td>
                <td><input class="form-control form-control-sm" name="items[{{ $index }}][discount_amount]" type="number" min="0" step="0.01" value="{{ $item->discount_type === 'PERCENTAGE' ? $item->discount_percentage : $item->discount_amount }}" data-correction-discount-value><input type="hidden" name="items[{{ $index }}][discount_percentage]" value="{{ $item->discount_percentage }}" data-correction-discount-percentage></td></tr>
        @endforeach
        </tbody></table></div>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Tipo descuento adicional</label><select class="form-select" name="additional_discount_type"><option value="FIXED" @selected($invoice->sale->additional_discount_type === 'FIXED')>Importe</option><option value="PERCENTAGE" @selected($invoice->sale->additional_discount_type === 'PERCENTAGE')>Porcentaje</option></select></div>
            <div class="col-md-4"><label class="form-label">Descuento adicional</label><input class="form-control" name="total_discount" type="number" min="0" step="0.01" value="{{ $invoice->sale->discount_amount }}"></div>
            <div class="col-md-4"><label class="form-label">Porcentaje adicional</label><input class="form-control" name="additional_discount_percentage" type="number" min="0" max="100" step="0.01" value="{{ $invoice->sale->additional_discount_percentage }}"></div>
            <div class="col-md-4"><label class="form-label">Tipo de cambio</label><input class="form-control" name="exchange_rate" type="number" min="0.00001" step="0.00001" value="{{ $invoice->sale->exchange_rate ?: 1 }}"></div>
            <div class="col-md-4"><label class="form-label">Monto Gift Card</label><input class="form-control" name="gift_card_amount" type="number" min="0" step="0.01" value="{{ $invoice->sale->gift_card_amount ?: 0 }}" data-payment-gift-card></div>
        </div>
        @error('invoice')<div class="alert alert-danger mt-3">{{ $message }}</div>@enderror
        <div class="d-flex justify-content-end gap-2 mt-4"><a class="btn btn-link" href="{{ route('billing.invoices.index') }}">Cancelar</a><button class="btn btn-warning" type="submit">Corregir y reenviar</button></div>
    </form>
</div></div></div></div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const method = document.querySelector('[data-payment-method]'); const card = document.querySelector('[data-payment-card]'); const input = card?.querySelector('input'); const giftCard = document.querySelector('[data-payment-gift-card]');
    const sync = () => { const visible = method?.value === '2'; const usesGiftCard = method?.selectedOptions[0]?.dataset.isGiftCard === '1'; card?.classList.toggle('d-none', !visible); if (input) { input.required = visible; if (!visible) input.value = ''; } if (giftCard) { giftCard.disabled = !usesGiftCard; giftCard.required = usesGiftCard; if (!usesGiftCard) giftCard.value = '0'; } };
    method?.addEventListener('change', sync); input?.addEventListener('input', () => { const d = input.value.replace(/\D/g, '').slice(0,16); input.value = d.replace(/(.{4})/g, '$1 ').trim(); }); sync();
    document.querySelector('form')?.addEventListener('submit', () => document.querySelectorAll('[data-correction-discount-type]').forEach((type) => {
        const row = type.closest('tr'); const value = row?.querySelector('[data-correction-discount-value]'); const percentage = row?.querySelector('[data-correction-discount-percentage]');
        if (percentage) percentage.value = type.value === 'PERCENTAGE' ? value?.value || '0' : '';
    }));
});
</script>
@endsection
