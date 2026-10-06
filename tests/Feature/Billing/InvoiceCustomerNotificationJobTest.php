<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\InvoiceCustomerNotificationType;
use App\Enums\InvoicePrintFormat;
use App\Jobs\SendInvoiceCustomerNotificationJob;
use App\Models\SinInvoiceIssue;
use App\Notifications\InvoiceCancelledNotification;
use App\Notifications\InvoiceIssuedNotification;
use App\Services\Billing\PurchaseSaleInvoicePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class InvoiceCustomerNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
    }

    public function test_issued_invoice_email_contains_pdf_and_xml_attachments(): void
    {
        $invoice = $this->invoiceWithArtifacts();

        (new SendInvoiceCustomerNotificationJob($invoice->id, InvoiceCustomerNotificationType::Issued))
            ->handle(app(PurchaseSaleInvoicePdfService::class));

        Notification::assertSentOnDemand(InvoiceIssuedNotification::class, function ($notification) use ($invoice): bool {
            $mail = $notification->toMail(new \stdClass);

            return count($mail->rawAttachments) === 2
                && $notification->invoice->is($invoice);
        });
        self::assertNotNull($invoice->refresh()->issuance_notified_at);
    }

    public function test_cancellation_email_also_contains_original_artifacts(): void
    {
        $invoice = $this->invoiceWithArtifacts();

        (new SendInvoiceCustomerNotificationJob($invoice->id, InvoiceCustomerNotificationType::Cancelled))
            ->handle(app(PurchaseSaleInvoicePdfService::class));

        Notification::assertSentOnDemand(InvoiceCancelledNotification::class, function ($notification): bool {
            return count($notification->toMail(new \stdClass)->rawAttachments) === 2;
        });
        self::assertNotNull($invoice->refresh()->cancellation_notified_at);
    }

    public function test_email_always_attaches_half_page_even_when_system_printing_uses_roll(): void
    {
        $invoice = $this->invoiceWithArtifacts();
        $invoice->company->forceFill(['invoice_print_format' => InvoicePrintFormat::Roll])->save();

        (new SendInvoiceCustomerNotificationJob($invoice->id, InvoiceCustomerNotificationType::Issued))
            ->handle(app(PurchaseSaleInvoicePdfService::class));

        Notification::assertSentOnDemand(InvoiceIssuedNotification::class, function ($notification): bool {
            $attachments = $notification->toMail(new \stdClass)->rawAttachments;
            $pdf = collect($attachments)->firstWhere('name', 'factura-123.pdf');

            return is_array($pdf)
                && str_contains((string) ($pdf['data'] ?? ''), '/MediaBox [0.000000 0.000000 595.276000 419.528000]');
        });
    }

    private function invoiceWithArtifacts(): SinInvoiceIssue
    {
        $invoice = SinInvoiceIssue::factory()->create([
            'invoice_number' => 123,
            'xml_path' => 'invoices/test/factura.xml',
            'pdf_path' => 'invoices/test/factura.pdf',
            'pdf_hash' => hash('sha256', '%PDF-test'),
        ]);
        $invoice->customer->forceFill(['email' => 'cliente@example.test'])->save();
        Storage::disk('local')->put($invoice->xml_path, '<factura/>');
        Storage::disk('local')->put($invoice->pdf_path, '%PDF-test');

        return $invoice->refresh();
    }
}
