<?php

use App\Enums\SiatScheduledProcess;
use App\Jobs\RunSiatScheduledProcessJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$scheduledProcesses = [
    [SiatScheduledProcess::VerifyCommunication, 'verify_communication'],
    [SiatScheduledProcess::DetectRecovery, 'detect_recovery'],
    [SiatScheduledProcess::RegisterPendingEvents, 'register_pending_events'],
    [SiatScheduledProcess::BuildPackages, 'build_packages'],
    [SiatScheduledProcess::SendPackages, 'send_packages'],
    [SiatScheduledProcess::CheckValidations, 'check_validations'],
    [SiatScheduledProcess::RetryRecoverableErrors, 'retry_recoverable_errors'],
    [SiatScheduledProcess::VerifyDeadlines, 'verify_deadlines'],
    [SiatScheduledProcess::VerifyCertificates, 'verify_certificates'],
    [SiatScheduledProcess::VerifyCufd, 'verify_cufd'],
    [SiatScheduledProcess::VerifyCafc, 'verify_cafc'],
    [SiatScheduledProcess::MonitorOperationalAlerts, 'monitor_operational_alerts'],
];

foreach ($scheduledProcesses as [$process, $configurationKey]) {
    Schedule::job(new RunSiatScheduledProcessJob($process))
        ->cron((string) config("siat.monitoring.schedule.{$configurationKey}"))
        ->name('siat:monitoring:'.strtolower($process->value))
        ->onOneServer()
        ->withoutOverlapping(max(1, (int) ceil((int) config('siat.monitoring.lock_seconds', 300) / 60)));
}
