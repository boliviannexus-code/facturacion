<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Billing\InvoiceDocumentSector;
use App\Services\Billing\InvoiceTotalsCalculator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class InvoiceTotalsCalculatorTest extends TestCase
{
    #[DataProvider('validCases')]
    public function test_calculates_fiscal_totals(array $case): void
    {
        [$items, $additional, $type, $percentage, $currency, $rate, $giftCard, $expected, $giftCardPayment] = array_pad($case, 9, false);
        $result = (new InvoiceTotalsCalculator)->calculate($items, $additional, $type, $percentage, $currency, $rate, $giftCard, 1, $giftCardPayment);
        foreach ($expected as $key => $value) {
            self::assertSame($value, data_get($result, $key));
        }
    }

    public static function validCases(): array
    {
        return [
            'sin descuentos' => [[[['quantity' => 2, 'unit_price' => 100, 'discount_amount' => 0]], 0, 'FIXED', null, 1, 1, 0, ['subtotal_sum' => '200.00', 'total_amount' => '200.00']]],
            'descuento fijo item' => [[[['quantity' => 2, 'unit_price' => 100, 'discount_amount' => 20]], 0, 'FIXED', null, 1, 1, 0, ['items.0.subtotal' => '180.00']]],
            'porcentaje item' => [[[['quantity' => 3, 'unit_price' => '99.90', 'discount_type' => 'PERCENTAGE', 'discount_percentage' => 10]], 20, 'FIXED', null, 1, 1, 0, ['items.0.discount_amount' => '29.97', 'items.0.subtotal' => '269.73', 'total_amount' => '249.73']]],
            'descuento global porcentaje' => [[[['quantity' => 1, 'unit_price' => 500]], 0, 'PERCENTAGE', 10, 1, 1, 0, ['discount_additional' => '50.00', 'total_amount' => '450.00']]],
            'descuentos combinados' => [[[
                ['quantity' => 2, 'unit_price' => 100, 'discount_amount' => 20],
                ['quantity' => 1, 'unit_price' => 150, 'discount_amount' => 0],
            ], 30, 'FIXED', null, 1, 1, 0, ['subtotal_sum' => '330.00', 'total_amount' => '300.00']]],
            'cantidad decimal' => [[[['quantity' => '1.25', 'unit_price' => '10.10']], 0, 'FIXED', null, 1, 1, 0, ['items.0.subtotal' => '12.63']]],
            'moneda extranjera' => [[[['quantity' => 1, 'unit_price' => 696]], 0, 'FIXED', null, 2, '6.96', 0, ['total_amount_currency' => '100.00']]],
            'gift card' => [[[['quantity' => 1, 'unit_price' => 100]], 0, 'FIXED', null, 1, 1, 20, ['total_amount_subject_to_vat' => '80.00'], true]],
            'gift card cubre el total' => [[[['quantity' => 1, 'unit_price' => 100]], 0, 'FIXED', null, 1, 1, 100, ['total_amount_subject_to_vat' => '0.00'], true]],
        ];
    }

    #[DataProvider('invalidCases')]
    public function test_rejects_inconsistent_discounts(array $items, mixed $additional, string $message): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($message);
        (new InvoiceTotalsCalculator)->calculate($items, $additional);
    }

    public static function invalidCases(): array
    {
        return [
            'item supera bruto' => [[['quantity' => 1, 'unit_price' => 10, 'discount_amount' => 11]], 0, 'no puede superar'],
            'global supera subtotal' => [[['quantity' => 1, 'unit_price' => 10]], 11, 'no puede superar'],
            'bonificacion total bloqueada' => [[['quantity' => 1, 'unit_price' => 10, 'discount_amount' => 10]], 0, 'bonificación total'],
            'precio cero sin gift card' => [[['quantity' => 1, 'unit_price' => 0]], 0, 'debe ser mayor a cero'],
            'descuento global deja total cero' => [[['quantity' => 1, 'unit_price' => 10]], 10, 'debe ser mayor a cero'],
        ];
    }

    public function test_rejects_gift_card_amount_with_another_payment_method(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('método de pago Gift Card');

        (new InvoiceTotalsCalculator)->calculate(
            [['quantity' => 1, 'unit_price' => 100]],
            giftCardAmount: 100,
        );
    }

    public function test_gift_card_payment_requires_a_positive_gift_card_amount(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('importe de Gift Card mayor a cero');

        (new InvoiceTotalsCalculator)->calculate(
            [['quantity' => 1, 'unit_price' => 100]],
            giftCardPayment: true,
        );
    }

    public function test_zero_rate_sector_always_has_zero_taxable_amount(): void
    {
        $result = (new InvoiceTotalsCalculator)->calculate(
            [['quantity' => 2, 'unit_price' => 100]],
            documentSectorCode: InvoiceDocumentSector::ZERO_RATE,
        );

        self::assertSame('200.00', $result['total_amount']);
        self::assertSame('0.00', $result['total_amount_subject_to_vat']);
    }
}
