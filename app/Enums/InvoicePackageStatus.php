<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoicePackageStatus: string
{
    case Created = 'CREATED';
    case PendingSend = 'PENDING_SEND';
    case Sent = 'SENT';
    case PendingValidation = 'PENDING_VALIDATION';
    case Validated = 'VALIDATED';
    case Observed = 'OBSERVED';
    case Rejected = 'REJECTED';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Creado',
            self::PendingSend => 'Pendiente de envío',
            self::Sent => 'Enviado al SIN',
            self::PendingValidation => 'Pendiente de validación',
            self::Validated => 'Validado por el SIN',
            self::Observed => 'Observado por el SIN',
            self::Rejected => 'Rechazado por el SIN',
            self::Failed => 'Envío fallido',
        };
    }
}
