<?php

namespace App\Enums;

enum SiatEnvironment: int
{
    case Production = 1;
    case TestingAndPilot = 2;

    public function label(): string
    {
        return match ($this) {
            self::Production => 'Produccion',
            self::TestingAndPilot => 'Pruebas y Piloto',
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
