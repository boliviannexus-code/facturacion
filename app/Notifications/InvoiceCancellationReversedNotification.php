<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SinInvoiceIssue;
use App\Notifications\Concerns\AttachesInvoiceArtifacts;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class InvoiceCancellationReversedNotification extends Notification
{
    use AttachesInvoiceArtifacts, Queueable;

    public function __construct(
        private readonly SinInvoiceIssue $invoice,
        private readonly ?string $halfPagePdf = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Reversión de anulación de factura N° '.$this->invoice->invoice_number)
            ->greeting('Estimado(a) cliente:')
            ->line('Le informamos que la anulación de la factura N° '.$this->invoice->invoice_number.' fue revertida ante el Servicio de Impuestos Nacionales.')
            ->line('Código de autorización (CUF): '.$this->invoice->cuf)
            ->line('La factura volvió al estado VÁLIDO. Adjuntamos nuevamente su representación gráfica y XML.')
            ->line('Esta comunicación es privada y se envía al correo registrado del comprador.');

        return $this->attachInvoiceArtifacts($message, $this->invoice, $this->halfPagePdf);
    }
}
