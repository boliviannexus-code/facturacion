<?php

declare(strict_types=1);

namespace App\Services\Siat\Monitoring;

use App\Enums\InvoicePackageStatus;
use App\Enums\ManualContingencyInvoiceStatus;
use App\Enums\SiatScheduledProcess;
use App\Enums\SignificantEventStatus;
use App\Jobs\BuildContingencyPackagesJob;
use App\Jobs\CheckPackageValidationJob;
use App\Jobs\DetectContingencyRecoveryJob;
use App\Jobs\RegisterSignificantEventJob;
use App\Jobs\SendContingencyPackageJob;
use App\Jobs\SendManualCafcInvoiceJob;
use App\Jobs\VerifySiatCommunicationJob;
use App\Models\SinApiToken;
use App\Models\SinInvoicePackage;
use App\Models\SinManualContingencyInvoice;
use App\Models\SinPointOfSale;
use App\Models\SinSignificantEvent;

final class SiatScheduledProcessService
{
    public function __construct(
        private readonly SiatAlertMonitorService $alerts,
        private readonly SiatAlertManager $alertManager,
    ) {}

    public function run(SiatScheduledProcess $process): int
    {
        return match ($process) {
            SiatScheduledProcess::VerifyCommunication => $this->verifyCommunication(),
            SiatScheduledProcess::DetectRecovery => $this->detectRecovery(),
            SiatScheduledProcess::RegisterPendingEvents => $this->registerPendingEvents(),
            SiatScheduledProcess::BuildPackages => $this->buildPackages(),
            SiatScheduledProcess::SendPackages => $this->sendPackages(),
            SiatScheduledProcess::CheckValidations => $this->checkValidations(),
            SiatScheduledProcess::RetryRecoverableErrors => $this->retryRecoverableErrors(),
            SiatScheduledProcess::VerifyDeadlines => $this->alerts->scanDeadlines(),
            SiatScheduledProcess::VerifyCertificates => $this->alerts->scanCertificates(),
            SiatScheduledProcess::VerifyCufd => $this->alerts->scanCufd(),
            SiatScheduledProcess::VerifyCafc => $this->alerts->scanCafc(),
            SiatScheduledProcess::MonitorOperationalAlerts => $this->alerts->scanOperationalAlerts(),
        };
    }

    private function verifyCommunication(): int
    {
        $count = 0;
        SinApiToken::query()->withoutGlobalScope('company')
            ->whereDate('starts_at', '<=', today())->whereDate('ends_at', '>=', today())
            ->orderBy('id')->eachById(function (SinApiToken $token) use (&$count): void {
                $pointIds = SinPointOfSale::query()->withoutGlobalScope('company')
                    ->where('company_id', $token->company_id)->where('is_active', true)->pluck('id');

                if ($pointIds->isEmpty()) {
                    VerifySiatCommunicationJob::dispatch((int) $token->company_id, (int) $token->id);
                    $count++;

                    return;
                }

                $pointIds->each(function (int $pointId) use ($token, &$count): void {
                    VerifySiatCommunicationJob::dispatch((int) $token->company_id, (int) $token->id, $pointId);
                    $count++;
                });
            });

        return $count;
    }

    private function detectRecovery(): int
    {
        return $this->dispatchEvents(
            [SignificantEventStatus::Open, SignificantEventStatus::RecoveryDetected, SignificantEventStatus::PendingRegistration],
            static fn (SinSignificantEvent $event) => DetectContingencyRecoveryJob::dispatch(
                (int) $event->company_id, (int) $event->id, $event->user_id,
            ),
        );
    }

    private function registerPendingEvents(): int
    {
        return $this->dispatchEvents(
            [SignificantEventStatus::RecoveryDetected, SignificantEventStatus::PendingRegistration],
            static fn (SinSignificantEvent $event) => RegisterSignificantEventJob::dispatch(
                (int) $event->company_id, (int) $event->id, $event->registered_by_user_id ?? $event->user_id,
            ),
        );
    }

    private function buildPackages(): int
    {
        return $this->dispatchEvents(
            [SignificantEventStatus::Registered, SignificantEventStatus::Packaging],
            static fn (SinSignificantEvent $event) => BuildContingencyPackagesJob::dispatch(
                (int) $event->company_id, (int) $event->id, $event->registered_by_user_id ?? $event->user_id,
            ),
        );
    }

    private function sendPackages(): int
    {
        return $this->dispatchPackages(
            [InvoicePackageStatus::Created, InvoicePackageStatus::PendingSend],
            static fn (SinInvoicePackage $package) => SendContingencyPackageJob::dispatch(
                (int) $package->company_id, (int) $package->id, $package->created_by_user_id,
            ),
        );
    }

    private function checkValidations(): int
    {
        return $this->dispatchPackages(
            [InvoicePackageStatus::Sent, InvoicePackageStatus::PendingValidation],
            static fn (SinInvoicePackage $package) => CheckPackageValidationJob::dispatch(
                (int) $package->company_id, (int) $package->id, $package->sent_by_user_id ?? $package->created_by_user_id,
            ),
        );
    }

    private function retryRecoverableErrors(): int
    {
        $count = $this->registerPendingEvents() + $this->sendPackages() + $this->checkValidations();
        SinManualContingencyInvoice::query()->withoutGlobalScope('company')
            ->where('manual_status', ManualContingencyInvoiceStatus::PendingSend)
            ->orderBy('id')->eachById(function (SinManualContingencyInvoice $manual) use (&$count): void {
                SendManualCafcInvoiceJob::dispatch((int) $manual->company_id, (int) $manual->id, $manual->transcribed_by_user_id);
                $count++;
            });

        return $count + $this->alertManager->retryFailedNotifications();
    }

    /** @param array<int, SignificantEventStatus> $statuses */
    private function dispatchEvents(array $statuses, callable $dispatch): int
    {
        $count = 0;
        SinSignificantEvent::query()->withoutGlobalScope('company')
            ->whereNull('closed_at')
            ->where('requires_manual_processing', false)
            ->whereIn('event_status', $statuses)
            ->orderBy('id')->eachById(function (SinSignificantEvent $event) use ($dispatch, &$count): void {
                $dispatch($event);
                $count++;
            });

        return $count;
    }

    /** @param array<int, InvoicePackageStatus> $statuses */
    private function dispatchPackages(array $statuses, callable $dispatch): int
    {
        $count = 0;
        SinInvoicePackage::query()->withoutGlobalScope('company')
            ->whereIn('package_status', $statuses)
            ->whereDoesntHave('significantEvent', fn ($query) => $query->where('requires_manual_processing', true))
            ->orderBy('id')->eachById(function (SinInvoicePackage $package) use ($dispatch, &$count): void {
                $dispatch($package);
                $count++;
            });

        return $count;
    }
}
