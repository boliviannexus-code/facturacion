<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SinInvoiceIssue;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class InvoiceCancelledNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly SinInvoiceIssue $invoice) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Anulación de factura N° '.$this->invoice->invoice_number)
            ->greeting('Notificación de anulación de documento fiscal')
            ->line('Le informamos que el siguiente documento fiscal fue anulado ante el Servicio de Impuestos Nacionales:')
            ->line('Código de autorización (CUF): '.$this->invoice->cuf)
            ->line('Número de factura: '.$this->invoice->invoice_number)
            ->line('Motivo: '.$this->invoice->cancellation_reason)
            ->line('Esta comunicación es privada y fue enviada al correo registrado para el comprador.');
    }
}
