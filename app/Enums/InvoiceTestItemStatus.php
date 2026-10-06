<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceTestItemStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En espera',
            self::Running => 'Emitiendo',
            self::Succeeded => 'Emitida',
            self::Failed => 'Fallida',
        };
    }
}
