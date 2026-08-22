<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\SinCatalogItem;
use App\Services\Billing\PaymentMethodPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PaymentMethodPolicyTest extends TestCase
{
    #[DataProvider('descriptions')]
    public function test_classifies_official_gift_card_payment_descriptions(string $description, bool $expected): void
    {
        $method = new SinCatalogItem(['description' => $description]);

        self::assertSame($expected, (new PaymentMethodPolicy)->catalogItemIsGiftCard($method));
    }

    public static function descriptions(): array
    {
        return [
            'gift card' => ['GIFT-CARD', true],
            'gift combinado sin card' => ['TARJETA – GIFT', true],
            'gift con transferencia' => ['TRANSFERENCIA BANCARIA – GIFT-CARD', true],
            'tarjeta regalo' => ['TARJETA REGALO', true],
            'efectivo' => ['EFECTIVO', false],
            'tarjeta' => ['TARJETA', false],
        ];
    }
}
