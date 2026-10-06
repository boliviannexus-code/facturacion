<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SinInvoiceIssue;
use App\Notifications\Concerns\AttachesInvoiceArtifacts;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class InvoiceIssuedNotification extends Notification
{
    use AttachesInvoiceArtifacts, Queueable;

    public function __construct(
        public readonly SinInvoiceIssue $invoice,
        public readonly ?string $halfPagePdf = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Factura N° '.$this->invoice->invoice_number)
            ->greeting('Estimado(a) cliente:')
            ->line('Adjuntamos la representación gráfica y el XML de su factura emitida.')
            ->line('Número de factura: '.$this->invoice->invoice_number)
            ->line('Código de autorización (CUF): '.$this->invoice->cuf)
            ->line('Monto total: Bs '.number_format((float) $this->invoice->total_amount, 2, '.', ','))
            ->line('Conserve estos documentos para sus registros.');

        return $this->attachInvoiceArtifacts($message, $this->invoice, $this->halfPagePdf);
    }
}
