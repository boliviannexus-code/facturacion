<?php

namespace App\Enums;

enum SiatModality: int
{
    case ElectronicOnline = 1;
    case ComputerizedOnline = 2;

    public function label(): string
    {
        return match ($this) {
            self::ElectronicOnline => 'Electronica en Linea',
            self::ComputerizedOnline => 'Computarizada en Linea',
        };
    }

    /**
     * @return array<int, string>
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
     * @return array<int, int>
     */
    public static function values(): array
    {
        return array_map(
            fn (self $case): int => $case->value,
            self::cases()
        );
    }
}
