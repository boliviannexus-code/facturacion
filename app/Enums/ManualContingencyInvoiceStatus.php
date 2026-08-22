<?php

declare(strict_types=1);

namespace App\Enums;

enum ManualContingencyInvoiceStatus: string
{
    case PendingTranscription = 'PENDING_TRANSCRIPTION';
    case Transcribed = 'TRANSCRIBED';
    case PendingSend = 'PENDING_SEND';
    case Validated = 'VALIDATED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::PendingTranscription => 'Pendiente de transcripción',
            self::Transcribed => 'Transcrita',
            self::PendingSend => 'Pendiente de envío',
            self::Validated => 'Validada',
            self::Rejected => 'Rechazada',
            self::Cancelled => 'Anulada',
        };
    }
}
