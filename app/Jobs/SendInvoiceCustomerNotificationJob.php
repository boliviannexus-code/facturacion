<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InvoiceCustomerNotificationType;
use App\Enums\InvoicePrintFormat;
use App\Models\SinInvoiceIssue;
use App\Notifications\InvoiceCancellationReversedNotification;
use App\Notifications\InvoiceCancelledNotification;
use App\Notifications\InvoiceIssuedNotification;
use App\Services\Billing\PurchaseSaleInvoicePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;
use Throwable;

final class SendInvoiceCustomerNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $invoiceId,
        public readonly InvoiceCustomerNotificationType $type,
    ) {}

    public function uniqueId(): string
    {
        return $this->invoiceId.'|'.$this->type->value;
    }

    public function handle(PurchaseSaleInvoicePdfService $pdfService): void
    {
        $invoice = SinInvoiceIssue::query()->withoutGlobalScope('company')
            ->with(['company', 'customer', 'pointOfSale.branch', 'sale.items'])
            ->findOrFail($this->invoiceId);

        if (blank($invoice->customer?->email)) {
            return;
        }

        $halfPagePdf = $pdfService->render($invoice, InvoicePrintFormat::HalfPage);

        NotificationFacade::route('mail', (string) $invoice->customer->email)
            ->notifyNow($this->notification($invoice, $halfPagePdf));

        $invoice->forceFill([
            $this->notifiedAtColumn() => now(),
            $this->errorColumn() => null,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        SinInvoiceIssue::query()->withoutGlobalScope('company')->whereKey($this->invoiceId)->update([
            $this->errorColumn() => Str::limit($exception?->getMessage() ?? 'No se pudo enviar el correo.', 255),
        ]);
    }

    private function notification(SinInvoiceIssue $invoice, string $halfPagePdf): Notification
    {
        return match ($this->type) {
            InvoiceCustomerNotificationType::Issued => new InvoiceIssuedNotification($invoice, $halfPagePdf),
            InvoiceCustomerNotificationType::Cancelled => new InvoiceCancelledNotification($invoice, $halfPagePdf),
            InvoiceCustomerNotificationType::CancellationReversed => new InvoiceCancellationReversedNotification($invoice, $halfPagePdf),
        };
    }

    private function notifiedAtColumn(): string
    {
        return match ($this->type) {
            InvoiceCustomerNotificationType::Issued => 'issuance_notified_at',
            InvoiceCustomerNotificationType::Cancelled => 'cancellation_notified_at',
            InvoiceCustomerNotificationType::CancellationReversed => 'reversal_notified_at',
        };
    }

    private function errorColumn(): string
    {
        return match ($this->type) {
            InvoiceCustomerNotificationType::Issued => 'issuance_notification_error',
            InvoiceCustomerNotificationType::Cancelled => 'cancellation_notification_error',
            InvoiceCustomerNotificationType::CancellationReversed => 'reversal_notification_error',
        };
    }
}
