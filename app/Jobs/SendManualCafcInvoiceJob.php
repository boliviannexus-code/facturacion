<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CafcRangeStatus;
use App\Models\SinManualContingencyInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class SendManualCafcInvoiceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $companyId, public readonly int $manualInvoiceId, public readonly ?int $actorId = null)
    {
        $this->onQueue('siat-manual-cafc');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->companyId.':'.$this->manualInvoiceId;
    }

    public function backoff(): array
    {
        return [2, 5];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(5)->expireAfter(150)];
    }

    public function handle(): void
    {
        $manual = SinManualContingencyInvoice::query()->withoutGlobalScope('company')->where('company_id', $this->companyId)->findOrFail($this->manualInvoiceId);
        if ($manual->cafcRange->range_status !== CafcRangeStatus::Blocked || ! $manual->sin_significant_event_id) {
            return;
        }
        BuildContingencyPackagesJob::dispatch($this->companyId, (int) $manual->sin_significant_event_id, $this->actorId);
    }
}
