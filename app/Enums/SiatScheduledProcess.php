<?php

declare(strict_types=1);

namespace App\Enums;

enum SiatScheduledProcess: string
{
    case VerifyCommunication = 'VERIFY_COMMUNICATION';
    case DetectRecovery = 'DETECT_RECOVERY';
    case RegisterPendingEvents = 'REGISTER_PENDING_EVENTS';
    case BuildPackages = 'BUILD_PACKAGES';
    case SendPackages = 'SEND_PACKAGES';
    case CheckValidations = 'CHECK_VALIDATIONS';
    case RetryRecoverableErrors = 'RETRY_RECOVERABLE_ERRORS';
    case VerifyDeadlines = 'VERIFY_DEADLINES';
    case VerifyCertificates = 'VERIFY_CERTIFICATES';
    case VerifyCufd = 'VERIFY_CUFD';
    case VerifyCafc = 'VERIFY_CAFC';
    case MonitorOperationalAlerts = 'MONITOR_OPERATIONAL_ALERTS';
}
