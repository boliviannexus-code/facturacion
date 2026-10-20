<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use App\Models\SinInvoiceIssue;
use App\Models\User;
use App\Services\Billing\InvoiceIssuanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

final class ResendPendingOnlineInvoiceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    /** @var array<int, int> */
    public array $backoff = [5, 30];

    public function __construct(
        public readonly int $companyId,
        public readonly int $invoiceId,
        public readonly int $actorId,
    ) {
        $this->onQueue('siat-recovery');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->companyId.':'.$this->invoiceId;
    }

    public function handle(InvoiceIssuanceService $issuance): void
    {
        try {
            $invoice = SinInvoiceIssue::query()->withoutGlobalScope('company')
                ->where('company_id', $this->companyId)
                ->findOrFail($this->invoiceId);

            // Otro trabajador puede haberlo enviado mientras este trabajo esperaba en la cola.
            if ($invoice->fiscal_status !== InvoiceFiscalStatus::PendingOnlineSend
                || $invoice->emission_mode !== InvoiceEmissionMode::Online) {
                return;
            }

            $actor = User::query()->withoutGlobalScope('company')
                ->where('company_id', $this->companyId)
                ->findOrFail($this->actorId);

            $issuance->resendPendingOnline($invoice, $actor);
        } finally {
            Cache::forget('siat:invoice-resend:'.$this->companyId.':'.$this->invoiceId);
        }
    }
}
