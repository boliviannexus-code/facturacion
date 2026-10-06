<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Services\Siat\SiatWsdlRegistry;
use InvalidArgumentException;

final class InvoiceDocumentSector
{
    public const PURCHASE_SALE = 1;

    public const ZERO_RATE = 8;

    public static function supports(int $code): bool
    {
        return in_array($code, [self::PURCHASE_SALE, self::ZERO_RATE], true);
    }

    /** @return array<int, string> */
    public static function supported(): array
    {
        return [
            self::PURCHASE_SALE => self::title(self::PURCHASE_SALE),
            self::ZERO_RATE => self::title(self::ZERO_RATE),
        ];
    }

    public static function rootElement(int $code): string
    {
        return match ($code) {
            self::PURCHASE_SALE => 'facturaComputarizadaCompraVenta',
            self::ZERO_RATE => 'facturaComputarizadaTasaCero',
            default => throw self::unsupported($code),
        };
    }

    public static function schemaFilename(int $code): string
    {
        return self::rootElement($code).'.xsd';
    }

    public static function schemaConfigKey(int $code): string
    {
        return match ($code) {
            self::PURCHASE_SALE => 'purchase_sale',
            self::ZERO_RATE => 'zero_rate',
            default => throw self::unsupported($code),
        };
    }

    public static function wsdlKey(int $code): string
    {
        return match ($code) {
            self::PURCHASE_SALE => 'purchase_sale_invoice',
            self::ZERO_RATE => 'zero_rate_invoice',
            default => throw self::unsupported($code),
        };
    }

    public static function invoiceDocumentTypeCode(int $code): int
    {
        return match ($code) {
            self::PURCHASE_SALE => 1,
            self::ZERO_RATE => 2,
            default => throw self::unsupported($code),
        };
    }

    public static function defaultWsdl(int $code): string
    {
        return match ($code) {
            self::PURCHASE_SALE => SiatWsdlRegistry::PURCHASE_SALE_INVOICE,
            self::ZERO_RATE => SiatWsdlRegistry::ZERO_RATE_INVOICE,
            default => throw self::unsupported($code),
        };
    }

    public static function title(int $code): string
    {
        return match ($code) {
            self::PURCHASE_SALE => 'Factura compra-venta',
            self::ZERO_RATE => 'Factura Tasa Cero',
            default => throw self::unsupported($code),
        };
    }

    public static function fiscalSubtitle(int $code): string
    {
        return $code === self::ZERO_RATE
            ? '(Sin Derecho a Crédito Fiscal)'
            : '(Con Derecho a Crédito Fiscal)';
    }

    private static function unsupported(int $code): InvalidArgumentException
    {
        return new InvalidArgumentException("El documento sector {$code} no está implementado para emisión.");
    }
}
