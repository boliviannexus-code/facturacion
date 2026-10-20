<?php

declare(strict_types=1);

namespace App\Enums;

enum CafcRangeStatus: string
{
    case Available = 'AVAILABLE';
    case InUse = 'IN_USE';
    case Sent = 'SENT';
    case Exhausted = 'EXHAUSTED';
    case Expired = 'EXPIRED';
    case Blocked = 'BLOCKED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::InUse => 'En uso',
            self::Sent => 'Enviado',
            self::Exhausted => 'Agotado',
            self::Expired => 'Vencido',
            self::Blocked => 'Bloqueado',
            self::Cancelled => 'Cancelado',
        };
    }

    public function canConsume(): bool
    {
        return in_array($this, [self::Available, self::InUse], true);
    }
}
