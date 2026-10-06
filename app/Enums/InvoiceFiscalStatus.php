<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceFiscalStatus: string
{
    case NotIssued = 'NOT_ISSUED';
    case PendingOnlineSend = 'PENDING_ONLINE_SEND';
    case Validated = 'VALIDATED';
    case Observed = 'OBSERVED';
    case Rejected = 'REJECTED';
    case UncertainSend = 'UNCERTAIN_SEND';
    case OfflineIssued = 'OFFLINE_ISSUED';
    case PendingPackage = 'PENDING_PACKAGE';
    case Packaged = 'PACKAGED';
    case PackageSent = 'PACKAGE_SENT';
    case ValidatedAfterContingency = 'VALIDATED_AFTER_CONTINGENCY';
    case ManualPendingTranscription = 'MANUAL_PENDING_TRANSCRIPTION';
    case ManualTranscribed = 'MANUAL_TRANSCRIBED';
    case ManualPendingSend = 'MANUAL_PENDING_SEND';
    case ManualValidated = 'MANUAL_VALIDATED';
    case CancellationPending = 'CANCELLATION_PENDING';
    case CancelledInSiat = 'CANCELLED_IN_SIAT';
    case ReversalPending = 'REVERSAL_PENDING';
    case ReversedInSiat = 'REVERSED_IN_SIAT';

    public function label(): string
    {
        return match ($this) {
            self::NotIssued => 'No emitida',
            self::PendingOnlineSend => 'Pendiente de envío al SIN',
            self::Validated => 'Validada por el SIN',
            self::Observed => 'Observada por el SIN',
            self::Rejected => 'Rechazada por el SIN',
            self::UncertainSend => 'Envío al SIN por confirmar',
            self::OfflineIssued => 'Emitida fuera de línea',
            self::PendingPackage => 'Pendiente de paquete',
            self::Packaged => 'Incluida en paquete',
            self::PackageSent => 'Paquete enviado al SIN',
            self::ValidatedAfterContingency => 'Validada después de contingencia',
            self::ManualPendingTranscription => 'Manual pendiente de transcripción',
            self::ManualTranscribed => 'Manual transcrita',
            self::ManualPendingSend => 'Manual pendiente de envío',
            self::ManualValidated => 'Manual validada por el SIN',
            self::CancellationPending => 'Anulación pendiente',
            self::CancelledInSiat => 'Anulada en el SIN',
            self::ReversalPending => 'Reversión pendiente',
            self::ReversedInSiat => 'Válida por reversión en el SIN',
        };
    }
}
