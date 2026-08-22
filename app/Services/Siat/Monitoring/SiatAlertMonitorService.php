<?php

declare(strict_types=1);

namespace App\Services\Siat\Monitoring;

use App\Enums\CafcRangeStatus;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\InvoicePackageStatus;
use App\Enums\ManualContingencyInvoiceStatus;
use App\Enums\SiatAlertSeverity;
use App\Enums\SiatAlertType;
use App\Enums\SignificantEventStatus;
use App\Models\Company;
use App\Models\SinAuthorization;
use App\Models\SinCafcRange;
use App\Models\SinCufd;
use App\Models\SinInvoiceIssue;
use App\Models\SinInvoicePackage;
use App\Models\SinManualContingencyInvoice;
use App\Models\SinSignificantEvent;
use Illuminate\Database\Eloquent\Builder;

final class SiatAlertMonitorService
{
    public function __construct(private readonly SiatAlertManager $alerts) {}

    public function scanOperationalAlerts(): int
    {
        return $this->forEachCompany(fn (Company $company): int => $this->scanOperationalForCompany($company));
    }

    public function scanDeadlines(): int
    {
        return $this->forEachCompany(fn (Company $company): int => $this->scanDeadlinesForCompany($company));
    }

    public function scanCertificates(): int
    {
        return $this->forEachCompany(fn (Company $company): int => $this->scanCertificatesForCompany($company));
    }

    public function scanCufd(): int
    {
        return $this->forEachCompany(fn (Company $company): int => $this->scanCufdForCompany($company));
    }

    public function scanCafc(): int
    {
        return $this->forEachCompany(fn (Company $company): int => $this->scanCafcForCompany($company));
    }

    private function scanOperationalForCompany(Company $company): int
    {
        $types = [
            SiatAlertType::ContingencyStarted,
            SiatAlertType::ConnectionRecovered,
            SiatAlertType::EventPendingRegistration,
            SiatAlertType::InvoicesPendingSend,
            SiatAlertType::PackageRejected,
            SiatAlertType::PackageObserved,
            SiatAlertType::ManualInvoicesPendingTranscription,
        ];
        $keys = [];
        $events = SinSignificantEvent::query()->withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->whereNull('closed_at')
            ->whereNotIn('event_status', [SignificantEventStatus::Completed, SignificantEventStatus::Expired])
            ->get();

        foreach ($events as $event) {
            if ($event->manual_review_required) {
                $keys[] = $this->record(new SiatAlertDefinition(
                    companyId: (int) $company->id,
                    type: SiatAlertType::EventPendingRegistration,
                    severity: SiatAlertSeverity::Critical,
                    scopeKey: 'event:'.$event->id,
                    title: 'Evento rechazado — revisión manual requerida',
                    message: "El evento significativo #{$event->id} no admite reintento automático. Último resultado del SIAT: ".($event->message ?: 'rechazo sin detalle.'),
                    branchId: $event->sin_branch_id,
                    pointOfSaleId: $event->sin_point_of_sale_id,
                    significantEventId: (int) $event->id,
                ));

                continue;
            }

            $keys[] = $this->record(new SiatAlertDefinition(
                companyId: (int) $company->id,
                type: SiatAlertType::ContingencyStarted,
                severity: SiatAlertSeverity::Warning,
                scopeKey: 'event:'.$event->id,
                title: 'Contingencia iniciada',
                message: "El evento #{$event->id} permanece abierto desde ".$event->started_at->format('d/m/Y H:i:s').'.',
                branchId: $event->sin_branch_id,
                pointOfSaleId: $event->sin_point_of_sale_id,
                significantEventId: (int) $event->id,
            ));

            if ($event->recovery_detected_at !== null) {
                $keys[] = $this->record(new SiatAlertDefinition(
                    companyId: (int) $company->id,
                    type: SiatAlertType::ConnectionRecovered,
                    severity: SiatAlertSeverity::Info,
                    scopeKey: 'event:'.$event->id,
                    title: 'Conexión con el SIAT recuperada',
                    message: "Se detectó recuperación para el evento #{$event->id} a las ".$event->recovery_detected_at->format('d/m/Y H:i:s').'.',
                    branchId: $event->sin_branch_id,
                    pointOfSaleId: $event->sin_point_of_sale_id,
                    significantEventId: (int) $event->id,
                ));
            }

            if (in_array($event->event_status, [
                SignificantEventStatus::RecoveryDetected,
                SignificantEventStatus::PendingRegistration,
                SignificantEventStatus::Failed,
            ], true)) {
                $keys[] = $this->record(new SiatAlertDefinition(
                    companyId: (int) $company->id,
                    type: SiatAlertType::EventPendingRegistration,
                    severity: SiatAlertSeverity::Critical,
                    scopeKey: 'event:'.$event->id,
                    title: 'Evento pendiente de registro',
                    message: "El evento significativo #{$event->id} requiere registro o reintento ante el SIAT.",
                    branchId: $event->sin_branch_id,
                    pointOfSaleId: $event->sin_point_of_sale_id,
                    significantEventId: (int) $event->id,
                ));
            }
        }

        $pendingInvoiceStatuses = [
            InvoiceFiscalStatus::PendingOnlineSend,
            InvoiceFiscalStatus::OfflineIssued,
            InvoiceFiscalStatus::PendingPackage,
            InvoiceFiscalStatus::Packaged,
            InvoiceFiscalStatus::PackageSent,
            InvoiceFiscalStatus::ManualPendingSend,
        ];
        $invoiceGroups = SinInvoiceIssue::query()->withoutGlobalScope('company')
            ->selectRaw('sin_branch_id, sin_point_of_sale_id, count(*) AS aggregate_count, min(issued_at) AS oldest_at')
            ->where('company_id', $company->id)
            ->whereIn('fiscal_status', $pendingInvoiceStatuses)
            ->groupBy('sin_branch_id', 'sin_point_of_sale_id')
            ->get();

        foreach ($invoiceGroups as $group) {
            $count = (int) $group->getAttribute('aggregate_count');
            $keys[] = $this->record(new SiatAlertDefinition(
                companyId: (int) $company->id,
                type: SiatAlertType::InvoicesPendingSend,
                severity: SiatAlertSeverity::Warning,
                scopeKey: $this->locationKey($group->sin_branch_id, $group->sin_point_of_sale_id),
                title: 'Facturas pendientes de envío',
                message: "Existen {$count} facturas pendientes de envío o regularización.",
                conditionCount: $count,
                branchId: $group->sin_branch_id,
                pointOfSaleId: $group->sin_point_of_sale_id,
                metadata: ['oldest_at' => $group->getAttribute('oldest_at')],
            ));
        }

        foreach ([
            InvoicePackageStatus::Rejected->value => [SiatAlertType::PackageRejected, SiatAlertSeverity::Critical, 'Paquete rechazado'],
            InvoicePackageStatus::Observed->value => [SiatAlertType::PackageObserved, SiatAlertSeverity::Warning, 'Paquete observado'],
        ] as $status => [$type, $severity, $title]) {
            $packages = SinInvoicePackage::query()->withoutGlobalScope('company')
                ->where('company_id', $company->id)->where('package_status', $status)->get();

            foreach ($packages as $package) {
                $keys[] = $this->record(new SiatAlertDefinition(
                    companyId: (int) $company->id,
                    type: $type,
                    severity: $severity,
                    scopeKey: 'package:'.$package->id,
                    title: $title,
                    message: "El paquete #{$package->package_number} del evento #{$package->sin_significant_event_id} está ".mb_strtolower($title).'.',
                    conditionCount: max(1, (int) $package->invoice_count),
                    branchId: $package->sin_branch_id,
                    pointOfSaleId: $package->sin_point_of_sale_id,
                    significantEventId: $package->sin_significant_event_id,
                    invoicePackageId: (int) $package->id,
                ));
            }
        }

        $manualGroups = SinManualContingencyInvoice::query()->withoutGlobalScope('company')
            ->selectRaw('sin_branch_id, sin_point_of_sale_id, count(*) AS aggregate_count, min(issued_manually_at) AS oldest_at')
            ->where('company_id', $company->id)
            ->where('manual_status', ManualContingencyInvoiceStatus::PendingTranscription)
            ->groupBy('sin_branch_id', 'sin_point_of_sale_id')
            ->get();

        foreach ($manualGroups as $group) {
            $count = (int) $group->getAttribute('aggregate_count');
            $keys[] = $this->record(new SiatAlertDefinition(
                companyId: (int) $company->id,
                type: SiatAlertType::ManualInvoicesPendingTranscription,
                severity: SiatAlertSeverity::Warning,
                scopeKey: $this->locationKey($group->sin_branch_id, $group->sin_point_of_sale_id),
                title: 'Facturas manuales pendientes de transcripción',
                message: "Existen {$count} facturas manuales CAFC pendientes de transcripción.",
                conditionCount: $count,
                branchId: $group->sin_branch_id,
                pointOfSaleId: $group->sin_point_of_sale_id,
                metadata: ['oldest_at' => $group->getAttribute('oldest_at')],
            ));
        }

        $this->alerts->resolveMissing((int) $company->id, $types, $keys);

        return count($keys);
    }

    private function scanDeadlinesForCompany(Company $company): int
    {
        $types = [SiatAlertType::RegularizationDeadlineExpiringSoon, SiatAlertType::RegularizationDeadlineExpired];
        $keys = [];
        $warningAt = now()->addMinutes(max(1, (int) config('siat.monitoring.thresholds.regularization_warning_minutes', 120)));
        $events = SinSignificantEvent::query()->withoutGlobalScope('company')
            ->where('company_id', $company->id)->whereNull('closed_at')->whereNotNull('expires_at')
            ->whereNotIn('event_status', [SignificantEventStatus::Completed, SignificantEventStatus::Expired])
            ->where('expires_at', '<=', $warningAt)->get();

        foreach ($events as $event) {
            $expired = $event->expires_at->isPast();
            $type = $expired ? SiatAlertType::RegularizationDeadlineExpired : SiatAlertType::RegularizationDeadlineExpiringSoon;
            $keys[] = $this->record(new SiatAlertDefinition(
                companyId: (int) $company->id,
                type: $type,
                severity: $expired ? SiatAlertSeverity::Critical : SiatAlertSeverity::Warning,
                scopeKey: 'event:'.$event->id,
                title: $type->label(),
                message: "El plazo del evento #{$event->id} ".($expired ? 'venció' : 'vence').' el '.$event->expires_at->format('d/m/Y H:i:s').'.',
                branchId: $event->sin_branch_id,
                pointOfSaleId: $event->sin_point_of_sale_id,
                significantEventId: (int) $event->id,
                metadata: ['expires_at' => $event->expires_at->toIso8601String()],
            ));
        }

        $this->alerts->resolveMissing((int) $company->id, $types, $keys);

        return count($keys);
    }

    private function scanCertificatesForCompany(Company $company): int
    {
        $type = SiatAlertType::CertificateExpiringSoon;
        $keys = [];
        $warningAt = now()->addDays(max(1, (int) config('siat.monitoring.thresholds.certificate_warning_days', 30)));
        $authorization = SinAuthorization::query()->withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->whereNotNull('certificate_expires_at')
            ->where('certificate_expires_at', '>', now())
            ->where('certificate_expires_at', '<=', $warningAt)
            ->first();

        if ($authorization) {
            $keys[] = $this->record(new SiatAlertDefinition(
                companyId: (int) $company->id,
                type: $type,
                severity: SiatAlertSeverity::Warning,
                scopeKey: 'authorization:'.$authorization->id,
                title: $type->label(),
                message: 'El certificado configurado vence el '.$authorization->certificate_expires_at->format('d/m/Y H:i:s').'.',
                authorizationId: (int) $authorization->id,
                metadata: ['expires_at' => $authorization->certificate_expires_at->toIso8601String()],
            ));
        }

        $this->alerts->resolveMissing((int) $company->id, [$type], $keys);

        return count($keys);
    }

    private function scanCufdForCompany(Company $company): int
    {
        $types = [SiatAlertType::CufdExpiringSoon, SiatAlertType::CufdExpired];
        $keys = [];
        $warningAt = now()->addMinutes(max(1, (int) config('siat.monitoring.thresholds.cufd_warning_minutes', 120)));
        $latestIds = SinCufd::query()->withoutGlobalScope('company')
            ->selectRaw('max(id)')
            ->where('company_id', $company->id)->usable()
            ->groupBy('company_id', 'sin_branch_id', 'sin_point_of_sale_id');
        $cufds = SinCufd::query()->withoutGlobalScope('company')
            ->whereIn('id', $latestIds)->where('expires_at', '<=', $warningAt)->get();

        foreach ($cufds as $cufd) {
            $expired = $cufd->expires_at?->isPast() ?? true;
            $type = $expired ? SiatAlertType::CufdExpired : SiatAlertType::CufdExpiringSoon;
            $keys[] = $this->record(new SiatAlertDefinition(
                companyId: (int) $company->id,
                type: $type,
                severity: $expired ? SiatAlertSeverity::Critical : SiatAlertSeverity::Warning,
                scopeKey: 'location:'.$this->locationKey($cufd->sin_branch_id, $cufd->sin_point_of_sale_id),
                title: $type->label(),
                message: 'El CUFD '.($expired ? 'venció' : 'vence').' el '.($cufd->expires_at?->format('d/m/Y H:i:s') ?? 'momento no registrado').'.',
                branchId: $cufd->sin_branch_id,
                pointOfSaleId: $cufd->sin_point_of_sale_id,
                cufdId: (int) $cufd->id,
                metadata: ['expires_at' => $cufd->expires_at?->toIso8601String()],
            ));
        }

        $this->alerts->resolveMissing((int) $company->id, $types, $keys);

        return count($keys);
    }

    private function scanCafcForCompany(Company $company): int
    {
        $types = [SiatAlertType::CafcNearlyExhausted, SiatAlertType::CafcExpired];
        $keys = [];
        $remainingThreshold = max(1, (int) config('siat.monitoring.thresholds.cafc_remaining_numbers', 20));
        $ranges = SinCafcRange::query()->withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->where(function (Builder $query) use ($remainingThreshold): void {
                $query->where('authorized_until', '<', today())
                    ->orWhere('range_status', CafcRangeStatus::Expired)
                    ->orWhere(function (Builder $active) use ($remainingThreshold): void {
                        $active->whereIn('range_status', [CafcRangeStatus::Available, CafcRangeStatus::InUse])
                            ->whereDate('authorized_until', '>=', today())
                            ->whereRaw('range_end - range_start + 1 - used_count - cancelled_count BETWEEN 1 AND ?', [$remainingThreshold]);
                    });
            })->get();

        foreach ($ranges as $range) {
            $expired = $range->authorized_until->lt(today()) || $range->range_status === CafcRangeStatus::Expired;
            $type = $expired ? SiatAlertType::CafcExpired : SiatAlertType::CafcNearlyExhausted;
            $keys[] = $this->record(new SiatAlertDefinition(
                companyId: (int) $company->id,
                type: $type,
                severity: $expired ? SiatAlertSeverity::Critical : SiatAlertSeverity::Warning,
                scopeKey: 'cafc:'.$range->id,
                title: $type->label(),
                message: $expired
                    ? "El rango CAFC {$range->cafc_code} venció el ".$range->authorized_until->format('d/m/Y').'.'
                    : "Al rango CAFC {$range->cafc_code} le quedan {$range->remaining_count} números.",
                conditionCount: max(1, $range->remaining_count),
                branchId: $range->sin_branch_id,
                pointOfSaleId: $range->sin_point_of_sale_id,
                cafcRangeId: (int) $range->id,
                metadata: ['remaining' => $range->remaining_count, 'authorized_until' => $range->authorized_until->toDateString()],
            ));
        }

        $this->alerts->resolveMissing((int) $company->id, $types, $keys);

        return count($keys);
    }

    private function record(SiatAlertDefinition $definition): string
    {
        $this->alerts->record($definition);

        return $definition->conditionKey();
    }

    private function locationKey(mixed $branchId, mixed $pointId): string
    {
        return ((int) $branchId).':'.((int) $pointId);
    }

    /** @param callable(Company): int $callback */
    private function forEachCompany(callable $callback): int
    {
        $count = 0;
        Company::query()->where('is_active', true)->orderBy('id')->eachById(function (Company $company) use ($callback, &$count): void {
            $count += $callback($company);
        });

        return $count;
    }
}
