<?php

declare(strict_types=1);

namespace App\Enums;

enum SiatMessageSeverity: string
{
    case Info = 'INFO';
    case Warning = 'WARNING';
    case Error = 'ERROR';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Información',
            self::Warning => 'Advertencia',
            self::Error => 'Error',
        };
    }
}
