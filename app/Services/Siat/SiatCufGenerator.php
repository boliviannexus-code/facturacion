<?php

namespace App\Services\Siat;

use DateTimeInterface;

class SiatCufGenerator
{
    public function generate(
        string $taxId,
        DateTimeInterface $issuedAt,
        int $branchCode,
        int $modalityCode,
        int $emissionTypeCode,
        int $invoiceDocumentTypeCode,
        int $documentSectorCode,
        int $invoiceNumber,
        int $pointOfSaleCode,
        string $controlCode,
    ): string {
        $base = str_pad($taxId, 13, '0', STR_PAD_LEFT)
            .$issuedAt->format('YmdHisv')
            .str_pad((string) $branchCode, 4, '0', STR_PAD_LEFT)
            .$modalityCode
            .$emissionTypeCode
            .$invoiceDocumentTypeCode
            .str_pad((string) $documentSectorCode, 2, '0', STR_PAD_LEFT)
            .str_pad((string) $invoiceNumber, 10, '0', STR_PAD_LEFT)
            .str_pad((string) $pointOfSaleCode, 4, '0', STR_PAD_LEFT);

        return strtoupper($this->toHex($base.$this->mod11($base)).$controlCode);
    }

    private function mod11(string $value): int
    {
        $sum = 0;
        $multiplier = 2;

        for ($index = strlen($value) - 1; $index >= 0; $index--) {
            $sum += (int) $value[$index] * $multiplier;
            $multiplier = $multiplier === 9 ? 2 : $multiplier + 1;
        }

        $digit = $sum % 11;

        return match ($digit) {
            10 => 1,
            default => $digit,
        };
    }

    private function toHex(string $decimal): string
    {
        $hex = '';

        while ($decimal !== '0') {
            $quotient = '';
            $remainder = 0;

            for ($index = 0, $length = strlen($decimal); $index < $length; $index++) {
                $number = $remainder * 10 + (int) $decimal[$index];
                $digit = intdiv($number, 16);
                $remainder = $number % 16;

                if ($quotient !== '' || $digit > 0) {
                    $quotient .= (string) $digit;
                }
            }

            $hex = strtoupper(dechex($remainder)).$hex;
            $decimal = $quotient === '' ? '0' : $quotient;
        }

        return $hex === '' ? '0' : $hex;
    }
}
