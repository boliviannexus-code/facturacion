<?php

declare(strict_types=1);

namespace App\Enums;

enum SiatAttemptStatus: string
{
    case Pending = 'PENDING';
    case Sending = 'SENDING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Uncertain = 'UNCERTAIN';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Sending => 'Enviando',
            self::Succeeded => 'Completado',
            self::Failed => 'Fallido',
            self::Uncertain => 'Por confirmar',
        };
    }
}
