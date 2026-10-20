<?php

declare(strict_types=1);

namespace App\Enums;

enum SignificantEventStatus: string
{
    case Open = 'OPEN';
    case RecoveryDetected = 'RECOVERY_DETECTED';
    case PendingRegistration = 'PENDING_REGISTRATION';
    case Registered = 'REGISTERED';
    case Packaging = 'PACKAGING';
    case Sending = 'SENDING';
    case Validating = 'VALIDATING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Expired = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierto',
            self::RecoveryDetected => 'Recuperación detectada',
            self::PendingRegistration => 'Pendiente de registro',
            self::Registered => 'Registrado en el SIN',
            self::Packaging => 'Preparando paquetes',
            self::Sending => 'Enviando al SIN',
            self::Validating => 'Validando en el SIN',
            self::Completed => 'Completado',
            self::Failed => 'Fallido',
            self::Expired => 'Vencido',
        };
    }
}
