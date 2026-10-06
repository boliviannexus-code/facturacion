<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SinMonitoringAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SiatContingencyAlertNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly SinMonitoringAlert $alert) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[SIAT] '.$this->alert->title)
            ->greeting('Alerta de contingencia SIAT')
            ->line($this->alert->message)
            ->line('Empresa: '.$this->alert->company->name)
            ->action('Abrir monitor de contingencias', route('billing.contingencies.index', [
                'company_id' => $this->alert->company_id,
                'branch_id' => $this->alert->sin_branch_id,
                'point_of_sale_id' => $this->alert->sin_point_of_sale_id,
            ]));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'alert_id' => $this->alert->id,
            'company_id' => $this->alert->company_id,
            'branch_id' => $this->alert->sin_branch_id,
            'point_of_sale_id' => $this->alert->sin_point_of_sale_id,
            'type' => $this->alert->alert_type->value,
            'severity' => $this->alert->severity->value,
            'title' => $this->alert->title,
            'message' => $this->alert->message,
            'url' => route('billing.contingencies.index', [
                'company_id' => $this->alert->company_id,
                'branch_id' => $this->alert->sin_branch_id,
                'point_of_sale_id' => $this->alert->sin_point_of_sale_id,
            ]),
            'detected_at' => $this->alert->first_detected_at->toIso8601String(),
        ];
    }
}
