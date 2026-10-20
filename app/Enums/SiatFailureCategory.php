<?php

declare(strict_types=1);

namespace App\Enums;

enum SiatFailureCategory: string
{
    case Communication = 'COMMUNICATION_ERROR';
    case InternetOutage = 'INTERNET_OUTAGE';
    case SiatUnavailable = 'SIAT_UNAVAILABLE';
    case Timeout = 'TIMEOUT';
    case InvalidXml = 'INVALID_XML';
    case InvalidToken = 'INVALID_TOKEN';
    case ExpiredCufd = 'EXPIRED_CUFD';
    case InvalidCuis = 'INVALID_CUIS';
    case ExpiredDigitalCertificate = 'EXPIRED_DIGITAL_CERTIFICATE';
    case Configuration = 'CONFIGURATION_ERROR';
    case InternalApplication = 'INTERNAL_APPLICATION_ERROR';
    case Database = 'DATABASE_ERROR';
    case Hardware = 'HARDWARE_FAILURE';
    case PowerOutage = 'POWER_OUTAGE';

    public function label(): string
    {
        return match ($this) {
            self::Communication => 'Error de comunicación con el SIN',
            self::InternetOutage => 'Corte del servicio de Internet',
            self::SiatUnavailable => 'Servicio del SIN no disponible',
            self::Timeout => 'Tiempo de espera agotado',
            self::InvalidXml => 'XML fiscal inválido',
            self::InvalidToken => 'Token API inválido',
            self::ExpiredCufd => 'CUFD vencido',
            self::InvalidCuis => 'CUIS inválido',
            self::ExpiredDigitalCertificate => 'Certificado digital vencido',
            self::Configuration => 'Error de configuración fiscal',
            self::InternalApplication => 'Error interno de la aplicación',
            self::Database => 'Error de base de datos',
            self::Hardware => 'Falla de hardware',
            self::PowerOutage => 'Corte de energía eléctrica',
        };
    }

    public function allowsSignificantEvent(): bool
    {
        return match ($this) {
            self::Communication,
            self::InternetOutage,
            self::SiatUnavailable,
            self::Timeout,
            self::Hardware,
            self::PowerOutage => true,
            default => false,
        };
    }
}
