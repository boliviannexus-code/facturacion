<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Illuminate\Validation\ValidationException;

final class InvoiceTotalsCalculator
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{items: array<int, array<string, string>>, subtotal_sum: string, discount_additional: string, total_amount: string, total_amount_currency: string, total_amount_subject_to_vat: string, gift_card_amount: string, exchange_rate: string}
     */
    public function calculate(array $items, string|int|float|null $additionalDiscount = '0', string $additionalDiscountType = 'FIXED', string|int|float|null $additionalDiscountPercentage = null, int $currencyCode = 1, string|int|float|null $exchangeRate = '1', string|int|float|null $giftCardAmount = '0', int $documentSectorCode = 1, bool $giftCardPayment = false): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Debe registrar al menos un producto o servicio.']);
        }
        $prepared = [];
        $subtotalSum = '0.00';
        foreach ($items as $index => $item) {
            $quantity = $this->decimal($item['quantity'] ?? null, 5, "items.$index.quantity");
            $unitPrice = $this->money($item['unit_price'] ?? null, "items.$index.unit_price");
            if (bccomp($quantity, '0', 5) <= 0) {
                $this->fail("items.$index.quantity", 'La cantidad debe ser mayor a cero.');
            }
            if (bccomp($unitPrice, '0', 2) < 0) {
                $this->fail("items.$index.unit_price", 'El precio unitario no puede ser negativo.');
            }
            $gross = $this->roundMoney(bcmul($quantity, $unitPrice, 7));
            $type = strtoupper((string) ($item['discount_type'] ?? 'FIXED'));
            if (! in_array($type, ['FIXED', 'PERCENTAGE'], true)) {
                $this->fail("items.$index.discount_type", 'El tipo de descuento no es válido.');
            }
            $percentage = $type === 'PERCENTAGE' ? $this->percentage($item['discount_percentage'] ?? null, "items.$index.discount_percentage") : null;
            $discount = $type === 'PERCENTAGE'
                ? $this->roundMoney(bcdiv(bcmul($gross, $percentage, 6), '100', 6))
                : $this->money($item['discount_amount'] ?? $item['discount'] ?? '0', "items.$index.discount_amount");
            if (bccomp($discount, $gross, 2) > 0) {
                $this->fail("items.$index.discount_amount", 'El descuento no puede superar el importe bruto del ítem.');
            }
            if ($documentSectorCode === 1 && bccomp($gross, '0', 2) > 0 && bccomp($discount, $gross, 2) === 0) {
                $this->fail("items.$index.discount_amount", 'La bonificación total no está soportada en Factura de Compra y Venta; utilice el documento sector que corresponda.');
            }
            $subtotal = bcsub($gross, $discount, 2);
            $subtotalSum = bcadd($subtotalSum, $subtotal, 2);
            $prepared[] = ['quantity' => $quantity, 'unit_price' => $unitPrice, 'discount_type' => $type,
                'discount_percentage' => $percentage ?? '0.00', 'discount_amount' => $discount, 'subtotal' => $subtotal];
        }
        $additionalType = strtoupper($additionalDiscountType);
        if (! in_array($additionalType, ['FIXED', 'PERCENTAGE'], true)) {
            $this->fail('additional_discount_type', 'El tipo de descuento adicional no es válido.');
        }
        $additionalPercentage = $additionalType === 'PERCENTAGE' ? $this->percentage($additionalDiscountPercentage, 'additional_discount_percentage') : null;
        $discountAdditional = $additionalType === 'PERCENTAGE'
            ? $this->roundMoney(bcdiv(bcmul($subtotalSum, $additionalPercentage, 6), '100', 6))
            : $this->money($additionalDiscount, 'total_discount');
        if (bccomp($discountAdditional, $subtotalSum, 2) > 0) {
            $this->fail('total_discount', 'El descuento adicional no puede superar la suma de subtotales.');
        }
        $total = bcsub($subtotalSum, $discountAdditional, 2);
        if (bccomp($total, '0', 2) <= 0) {
            $this->fail('total_amount', 'El monto total de la factura debe ser mayor a cero.');
        }
        $giftCard = $this->money($giftCardAmount, 'gift_card_amount');
        if (bccomp($giftCard, $total, 2) > 0) {
            $this->fail('gift_card_amount', 'El importe de Gift Card no puede superar el monto total.');
        }
        if (bccomp($giftCard, '0', 2) > 0 && ! $giftCardPayment) {
            $this->fail('payment_method_code', 'Selecciona el método de pago Gift Card para registrar este importe.');
        }
        if ($giftCardPayment && bccomp($giftCard, '0', 2) <= 0) {
            $this->fail('gift_card_amount', 'Ingresa un importe de Gift Card mayor a cero.');
        }
        $rate = $this->money($exchangeRate, 'exchange_rate');
        if (bccomp($rate, '0', 2) <= 0) {
            $this->fail('exchange_rate', 'El tipo de cambio debe ser mayor a cero.');
        }
        if ($currencyCode === 1 && bccomp($rate, '1.00', 2) !== 0) {
            $this->fail('exchange_rate', 'Para bolivianos el tipo de cambio debe ser 1.00.');
        }
        $currencyTotal = $currencyCode === 1 ? $total : $this->roundMoney(bcdiv($total, $rate, 6));

        return ['items' => $prepared, 'subtotal_sum' => $subtotalSum, 'discount_additional' => $discountAdditional,
            'total_amount' => $total, 'total_amount_currency' => $currencyTotal,
            'total_amount_subject_to_vat' => $documentSectorCode === InvoiceDocumentSector::ZERO_RATE
                ? '0.00'
                : bcsub($total, $giftCard, 2),
            'gift_card_amount' => $giftCard, 'exchange_rate' => $rate];
    }

    private function money(mixed $value, string $field): string
    {
        return $this->roundMoney($this->decimal($value ?? '0', 6, $field));
    }

    private function percentage(mixed $value, string $field): string
    {
        $v = $this->money($value, $field);
        if (bccomp($v, '0', 2) < 0 || bccomp($v, '100', 2) > 0) {
            $this->fail($field, 'El porcentaje debe estar entre 0 y 100.');
        }

        return $v;
    }

    private function roundMoney(string $value): string
    {
        return bcadd($value, '0.005', 2);
    }

    private function decimal(mixed $value, int $scale, string $field): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            $this->fail($field, 'Ingrese un valor numérico válido.');
        }

        return bcadd($value, '0', $scale);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
