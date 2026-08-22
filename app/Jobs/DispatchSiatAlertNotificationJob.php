<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SinMonitoringAlert;
use App\Notifications\SiatContingencyAlertNotification;
use App\Services\Siat\Monitoring\SiatAlertRecipientResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class DispatchSiatAlertNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout = 60;

    public int $uniqueFor;

    public function __construct(public readonly int $alertId)
    {
        $this->tries = max(1, (int) config('siat.monitoring.max_notification_attempts', 3));
        $this->uniqueFor = max(60, (int) config('siat.monitoring.job_unique_seconds', 300));
        $this->onQueue((string) config('siat.monitoring.queue', 'siat-monitoring'));
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'siat-alert:'.$this->alertId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return array_map(static fn (mixed $delay): int => max(0, (int) $delay), (array) config('siat.monitoring.notification_backoff', [10, 60, 300]));
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(10)->expireAfter(90)];
    }

    public function handle(SiatAlertRecipientResolver $recipients): void
    {
        $alert = SinMonitoringAlert::query()->withoutGlobalScope('company')->with('company')->findOrFail($this->alertId);
        $alert->increment('notification_attempts');
        $notifiables = $recipients->recipients($alert);
        $notification = new SiatContingencyAlertNotification($alert);

        if ((bool) config('siat.monitoring.channels.internal', true) && $alert->internal_notified_at === null) {
            if ($notifiables->isNotEmpty()) {
                Notification::sendNow($notifiables, $notification, ['database']);
            }
            $alert->forceFill(['internal_notified_at' => now()])->save();
        }

        if ((bool) config('siat.monitoring.channels.mail', false) && $alert->email_notified_at === null) {
            if ($notifiables->isNotEmpty()) {
                Notification::sendNow($notifiables, $notification, ['mail']);
            }
            $alert->forceFill(['email_notified_at' => now()])->save();
        }

        $alert->forceFill([
            'notification_failed_at' => null,
            'notification_error' => null,
        ])->save();
    }

    public function failed(Throwable $exception): void
    {
        SinMonitoringAlert::query()->withoutGlobalScope('company')->whereKey($this->alertId)->update([
            'notification_failed_at' => now(),
            'notification_error' => $exception::class,
            'updated_at' => now(),
        ]);
    }
}
