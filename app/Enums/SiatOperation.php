<?php

declare(strict_types=1);

namespace App\Enums;

enum SiatOperation: string
{
    case VerifyCommunication = 'VERIFY_COMMUNICATION';
    case ReceiveInvoice = 'RECEIVE_INVOICE';
    case RegisterSignificantEvent = 'REGISTER_SIGNIFICANT_EVENT';
    case ReceivePackage = 'RECEIVE_PACKAGE';
    case ValidatePackage = 'VALIDATE_PACKAGE';
    case VerifyInvoice = 'VERIFY_INVOICE';
    case CancelInvoice = 'CANCEL_INVOICE';
    case ReverseCancellation = 'REVERSE_CANCELLATION';

    public function label(): string
    {
        return match ($this) {
            self::VerifyCommunication => 'Verificar comunicación',
            self::ReceiveInvoice => 'Recibir factura',
            self::RegisterSignificantEvent => 'Registrar evento significativo',
            self::ReceivePackage => 'Recibir paquete',
            self::ValidatePackage => 'Validar paquete',
            self::VerifyInvoice => 'Verificar factura',
            self::CancelInvoice => 'Anular factura',
            self::ReverseCancellation => 'Revertir anulación',
        };
    }
}
