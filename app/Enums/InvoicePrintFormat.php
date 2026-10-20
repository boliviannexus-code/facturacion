<?php

namespace App\Enums;

enum InvoicePrintFormat: string
{
    case HalfPage = 'half_page';
    case Roll = 'roll';

    public function label(): string
    {
        return match ($this) {
            self::HalfPage => 'Media hoja',
            self::Roll => 'Rollo',
        };
    }

    public function qrSize(): int
    {
        return match ($this) {
            self::HalfPage => 2,
            self::Roll => 1,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            fn (self $case): string => $case->value,
            self::cases()
        );
    }

    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::HalfPage;
    }
}
