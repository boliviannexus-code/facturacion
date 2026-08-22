<?php

declare(strict_types=1);

namespace App\Services\Siat\Monitoring;

use App\Enums\SiatAlertStatus;
use App\Enums\SiatAlertType;
use App\Jobs\DispatchSiatAlertNotificationJob;
use App\Models\SinMonitoringAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SiatAlertManager
{
    public function record(SiatAlertDefinition $definition): SinMonitoringAlert
    {
        $key = $definition->conditionKey();
        [$alert] = DB::transaction(function () use ($definition, $key): array {
            $now = now();
            $created = DB::table('sin_monitoring_alerts')->insertOrIgnore([
                'company_id' => $definition->companyId,
                'sin_branch_id' => $definition->branchId,
                'sin_point_of_sale_id' => $definition->pointOfSaleId,
                'sin_significant_event_id' => $definition->significantEventId,
                'sin_invoice_package_id' => $definition->invoicePackageId,
                'sin_invoice_issue_id' => $definition->invoiceIssueId,
                'sin_manual_contingency_invoice_id' => $definition->manualInvoiceId,
                'sin_cufd_id' => $definition->cufdId,
                'sin_cafc_range_id' => $definition->cafcRangeId,
                'sin_authorization_id' => $definition->authorizationId,
                'alert_type' => $definition->type->value,
                'severity' => $definition->severity->value,
                'alert_status' => SiatAlertStatus::Active->value,
                'condition_key' => $key,
                'active_key' => $key,
                'title' => $definition->title,
                'message' => $definition->message,
                'condition_count' => max(1, $definition->conditionCount),
                'metadata' => $definition->metadata === [] ? null : json_encode($definition->metadata, JSON_THROW_ON_ERROR),
                'first_detected_at' => $now,
                'last_detected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]) === 1;

            $alert = SinMonitoringAlert::query()->withoutGlobalScope('company')
                ->where('active_key', $key)->lockForUpdate()->firstOrFail();

            if (! $created) {
                $alert->forceFill([
                    'severity' => $definition->severity,
                    'title' => $definition->title,
                    'message' => $definition->message,
                    'condition_count' => max(1, $definition->conditionCount),
                    'metadata' => $definition->metadata,
                    'last_detected_at' => $now,
                ])->save();
            }

            return [$alert->refresh(), $created];
        }, 3);

        $this->activateChannels($alert);

        return $alert->refresh();
    }

    /** @param array<int, SiatAlertType> $types @param array<int, string> $activeKeys */
    public function resolveMissing(int $companyId, array $types, array $activeKeys): int
    {
        return SinMonitoringAlert::query()->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->active()
            ->whereIn('alert_type', array_map(static fn (SiatAlertType $type): string => $type->value, $types))
            ->when($activeKeys !== [], fn ($query) => $query->whereNotIn('active_key', $activeKeys))
            ->update([
                'alert_status' => SiatAlertStatus::Resolved,
                'active_key' => null,
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function retryFailedNotifications(): int
    {
        $maximum = max(1, (int) config('siat.monitoring.max_notification_attempts', 3));
        $alerts = SinMonitoringAlert::query()->withoutGlobalScope('company')
            ->whereNotNull('notification_failed_at')
            ->where('notification_attempts', '<', $maximum)
            ->orderBy('notification_failed_at')
            ->limit(100)
            ->get();

        $alerts->each(fn (SinMonitoringAlert $alert) => DispatchSiatAlertNotificationJob::dispatch((int) $alert->id));

        return $alerts->count();
    }

    private function activateChannels(SinMonitoringAlert $alert): void
    {
        $updates = [];

        if ((bool) config('siat.monitoring.channels.panel', true) && $alert->panel_recorded_at === null) {
            $updates['panel_recorded_at'] = now();
        }

        if ((bool) config('siat.monitoring.channels.log', true) && $alert->technical_logged_at === null) {
            Log::warning('Alerta de monitoreo SIAT', [
                'alert_id' => $alert->id,
                'company_id' => $alert->company_id,
                'alert_type' => $alert->alert_type->value,
                'severity' => $alert->severity->value,
                'message' => $alert->message,
            ]);
            $updates['technical_logged_at'] = now();
        }

        $needsInternalNotification = (bool) config('siat.monitoring.channels.internal', true)
            && $alert->internal_notified_at === null;
        $needsEmailNotification = (bool) config('siat.monitoring.channels.mail', false)
            && $alert->email_notified_at === null;

        if (($needsInternalNotification || $needsEmailNotification) && $alert->notification_queued_at === null) {
            $updates['notification_queued_at'] = now();
        }

        if ($updates !== []) {
            $alert->forceFill($updates)->save();
        }

        if ($needsInternalNotification || $needsEmailNotification) {
            DispatchSiatAlertNotificationJob::dispatch((int) $alert->id);
        }
    }
}
