<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceTestBatchStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case Completed = 'COMPLETED';
    case CompletedWithErrors = 'COMPLETED_WITH_ERRORS';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Running => 'En ejecución',
            self::Completed => 'Completado',
            self::CompletedWithErrors => 'Completado con errores',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Running], true);
    }
}
