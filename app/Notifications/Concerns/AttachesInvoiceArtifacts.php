<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\SinInvoiceIssue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;

trait AttachesInvoiceArtifacts
{
    private function attachInvoiceArtifacts(MailMessage $message, SinInvoiceIssue $invoice, ?string $halfPagePdf = null): MailMessage
    {
        $disk = Storage::disk('local');
        $number = (string) ($invoice->invoice_number ?? $invoice->attempted_invoice_number ?? $invoice->id);

        if ($halfPagePdf !== null || ($invoice->pdf_path && $disk->exists($invoice->pdf_path))) {
            $message->attachData($halfPagePdf ?? $disk->get($invoice->pdf_path), "factura-{$number}.pdf", [
                'mime' => 'application/pdf',
            ]);
        }

        if ($invoice->xml_path && $disk->exists($invoice->xml_path)) {
            $message->attachData($disk->get($invoice->xml_path), "factura-{$number}.xml", [
                'mime' => 'application/xml',
            ]);
        }

        return $message;
    }
}
