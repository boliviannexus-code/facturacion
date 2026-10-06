<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InvoiceFiscalStatus;
use App\Enums\SignificantEventStatus;
use App\Models\SinInvoiceIssue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SynchronizeOfflineInvoiceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $companyId,
        public readonly int $invoiceId,
    ) {
        $this->onQueue('siat-synchronization');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->companyId.':'.$this->invoiceId;
    }

    public function handle(): void
    {
        $invoice = SinInvoiceIssue::query()
            ->withoutGlobalScope('company')
            ->with('significantEvent')
            ->where('company_id', $this->companyId)
            ->findOrFail($this->invoiceId);

        if ($invoice->fiscal_status !== InvoiceFiscalStatus::OfflineIssued) {
            return;
        }

        // El empaquetado solo puede comenzar despues del registro/recuperacion del evento.
        if (! in_array($invoice->significantEvent?->event_status, [
            SignificantEventStatus::Registered,
            SignificantEventStatus::Packaging,
        ], true)) {
            return;
        }

        BuildContingencyPackagesJob::dispatch(
            $this->companyId,
            (int) $invoice->sin_significant_event_id,
            $invoice->user_id,
        );
    }
}
