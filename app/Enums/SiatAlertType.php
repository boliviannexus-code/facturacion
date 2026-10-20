<?php

declare(strict_types=1);

namespace App\Enums;

enum SiatAlertType: string
{
    case ContingencyStarted = 'CONTINGENCY_STARTED';
    case ConnectionRecovered = 'CONNECTION_RECOVERED';
    case EventPendingRegistration = 'EVENT_PENDING_REGISTRATION';
    case InvoicesPendingSend = 'INVOICES_PENDING_SEND';
    case PackageRejected = 'PACKAGE_REJECTED';
    case PackageObserved = 'PACKAGE_OBSERVED';
    case ManualInvoicesPendingTranscription = 'MANUAL_INVOICES_PENDING_TRANSCRIPTION';
    case CufdExpiringSoon = 'CUFD_EXPIRING_SOON';
    case CufdExpired = 'CUFD_EXPIRED';
    case CertificateExpiringSoon = 'CERTIFICATE_EXPIRING_SOON';
    case CafcNearlyExhausted = 'CAFC_NEARLY_EXHAUSTED';
    case CafcExpired = 'CAFC_EXPIRED';
    case RegularizationDeadlineExpiringSoon = 'REGULARIZATION_DEADLINE_EXPIRING_SOON';
    case RegularizationDeadlineExpired = 'REGULARIZATION_DEADLINE_EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::ContingencyStarted => 'Contingencia iniciada',
            self::ConnectionRecovered => 'Conexión recuperada',
            self::EventPendingRegistration => 'Evento pendiente de registro',
            self::InvoicesPendingSend => 'Facturas pendientes de envío',
            self::PackageRejected => 'Paquete rechazado',
            self::PackageObserved => 'Paquete observado',
            self::ManualInvoicesPendingTranscription => 'Facturas manuales pendientes',
            self::CufdExpiringSoon => 'CUFD próximo a vencer',
            self::CufdExpired => 'CUFD vencido',
            self::CertificateExpiringSoon => 'Certificado próximo a vencer',
            self::CafcNearlyExhausted => 'CAFC próximo a agotarse',
            self::CafcExpired => 'CAFC vencido',
            self::RegularizationDeadlineExpiringSoon => 'Plazo próximo a vencer',
            self::RegularizationDeadlineExpired => 'Plazo vencido',
        };
    }
}
