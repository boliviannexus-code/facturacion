<?php

declare(strict_types=1);

namespace App\Enums;

enum SiatErrorType: string
{
    case Available = 'AVAILABLE';
    case NoInternet = 'NO_INTERNET';
    case Timeout = 'TIMEOUT';
    case DnsUnavailable = 'DNS_UNAVAILABLE';
    case SiatUnavailable = 'SIAT_UNAVAILABLE';
    case InvalidHttpResponse = 'INVALID_HTTP_RESPONSE';
    case InvalidToken = 'INVALID_TOKEN';
    case InvalidCuis = 'INVALID_CUIS';
    case InvalidCufd = 'INVALID_OR_EXPIRED_CUFD';
    case ExpiredCertificate = 'EXPIRED_CERTIFICATE';
    case InvalidXml = 'INVALID_XML';
    case CatalogError = 'CATALOG_ERROR';
    case AuthenticationError = 'AUTHENTICATION_ERROR';
    case LocalConfiguration = 'LOCAL_CONFIGURATION_ERROR';
    case Database = 'DATABASE_ERROR';
    case Unknown = 'UNKNOWN_ERROR';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::NoInternet,
            self::Timeout,
            self::DnsUnavailable,
            self::SiatUnavailable,
            self::InvalidHttpResponse => true,
            default => false,
        };
    }

    public function canOpenContingencyAfterRetries(): bool
    {
        return match ($this) {
            self::NoInternet,
            self::DnsUnavailable,
            self::SiatUnavailable,
            self::InvalidHttpResponse,
            self::Timeout => true,
            default => false,
        };
    }

    public function failureCategory(): ?SiatFailureCategory
    {
        return match ($this) {
            self::Available => null,
            self::NoInternet, self::DnsUnavailable => SiatFailureCategory::InternetOutage,
            self::Timeout => SiatFailureCategory::Timeout,
            self::SiatUnavailable => SiatFailureCategory::SiatUnavailable,
            self::InvalidHttpResponse => SiatFailureCategory::Communication,
            self::InvalidToken, self::AuthenticationError => SiatFailureCategory::InvalidToken,
            self::InvalidCuis => SiatFailureCategory::InvalidCuis,
            self::InvalidCufd => SiatFailureCategory::ExpiredCufd,
            self::ExpiredCertificate => SiatFailureCategory::ExpiredDigitalCertificate,
            self::InvalidXml => SiatFailureCategory::InvalidXml,
            self::CatalogError, self::LocalConfiguration => SiatFailureCategory::Configuration,
            self::Database => SiatFailureCategory::Database,
            self::Unknown => SiatFailureCategory::InternalApplication,
        };
    }
}
