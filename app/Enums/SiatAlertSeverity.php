<?php

declare(strict_types=1);

namespace App\Enums;

enum SiatAlertSeverity: string
{
    case Info = 'INFO';
    case Warning = 'WARNING';
    case Critical = 'CRITICAL';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Información',
            self::Warning => 'Advertencia',
            self::Critical => 'Crítica',
        };
    }
}
