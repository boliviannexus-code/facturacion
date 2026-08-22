<?php

return [
    'xsd' => [
        'purchase_sale' => resource_path('siat/xsd/facturaComputarizadaCompraVenta.xsd'),
        'zero_rate' => resource_path('siat/xsd/facturaComputarizadaTasaCero.xsd'),
    ],
    'communication' => [
        'timeout_seconds' => (int) env('SIAT_COMMUNICATION_TIMEOUT', 5),
        'retry_delays' => [0, 2, 5],
        'contingency_failure_threshold' => (int) env('SIAT_CONTINGENCY_FAILURE_THRESHOLD', 3),
        'job_unique_seconds' => (int) env('SIAT_HEALTH_JOB_UNIQUE_SECONDS', 60),
    ],
    'invoice_issuance' => [
        'internet_outage_event_code' => (int) env('SIAT_INTERNET_OUTAGE_EVENT_CODE', 1),
        'siat_unavailable_event_code' => (int) env('SIAT_UNAVAILABLE_EVENT_CODE', 2),
    ],
    'contingency_recovery' => [
        'registration_backoff' => [2, 5],
        'registration_claim_ttl_minutes' => (int) env('SIAT_EVENT_CLAIM_TTL_MINUTES', 5),
    ],
    'packages' => [
        'maximum_invoices' => 500,
        'claim_ttl_minutes' => (int) env('SIAT_PACKAGE_CLAIM_TTL_MINUTES', 5),
        'send_backoff' => [2, 5],
        'validation_backoff' => [5, 15, 30, 60],
        'validation_status_codes' => [
            'validated' => [908],
            'observed' => [904],
            'rejected' => [902],
        ],
    ],
    'monitoring' => [
        'queue' => env('SIAT_MONITORING_QUEUE', 'siat-monitoring'),
        'lock_seconds' => (int) env('SIAT_MONITORING_LOCK_SECONDS', 300),
        'job_unique_seconds' => (int) env('SIAT_MONITORING_JOB_UNIQUE_SECONDS', 300),
        'max_notification_attempts' => (int) env('SIAT_ALERT_MAX_ATTEMPTS', 3),
        'notification_backoff' => [10, 60, 300],
        'channels' => [
            'internal' => filter_var(env('SIAT_ALERT_INTERNAL', true), FILTER_VALIDATE_BOOL),
            'mail' => filter_var(env('SIAT_ALERT_MAIL', false), FILTER_VALIDATE_BOOL),
            'panel' => filter_var(env('SIAT_ALERT_PANEL', true), FILTER_VALIDATE_BOOL),
            'log' => filter_var(env('SIAT_ALERT_TECHNICAL_LOG', true), FILTER_VALIDATE_BOOL),
        ],
        'recipient_roles' => ['admin', 'manager', 'branch_admin', 'tax_responsible', 'technical_support', 'super_admin'],
        'thresholds' => [
            'cufd_warning_minutes' => (int) env('SIAT_CUFD_WARNING_MINUTES', 120),
            'certificate_warning_days' => (int) env('SIAT_CERTIFICATE_WARNING_DAYS', 30),
            'cafc_remaining_numbers' => (int) env('SIAT_CAFC_REMAINING_WARNING', 20),
            'regularization_warning_minutes' => (int) env('SIAT_REGULARIZATION_WARNING_MINUTES', 120),
        ],
        'schedule' => [
            'verify_communication' => env('SIAT_SCHEDULE_COMMUNICATION', '*/2 * * * *'),
            'detect_recovery' => env('SIAT_SCHEDULE_RECOVERY', '* * * * *'),
            'register_pending_events' => env('SIAT_SCHEDULE_EVENT_REGISTRATION', '*/2 * * * *'),
            'build_packages' => env('SIAT_SCHEDULE_BUILD_PACKAGES', '*/2 * * * *'),
            'send_packages' => env('SIAT_SCHEDULE_SEND_PACKAGES', '* * * * *'),
            'check_validations' => env('SIAT_SCHEDULE_PACKAGE_VALIDATION', '*/5 * * * *'),
            'retry_recoverable_errors' => env('SIAT_SCHEDULE_RETRIES', '*/5 * * * *'),
            'verify_deadlines' => env('SIAT_SCHEDULE_DEADLINES', '*/10 * * * *'),
            'verify_certificates' => env('SIAT_SCHEDULE_CERTIFICATES', '0 6 * * *'),
            'verify_cufd' => env('SIAT_SCHEDULE_CUFD', '*/10 * * * *'),
            'verify_cafc' => env('SIAT_SCHEDULE_CAFC', '0 * * * *'),
            'monitor_operational_alerts' => env('SIAT_SCHEDULE_ALERTS', '* * * * *'),
        ],
    ],
];
