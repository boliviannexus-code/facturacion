<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InvoicePackageStatus;
use App\Models\SinSignificantEvent;
use App\Models\User;
use App\Services\Billing\InvoicePackageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class BuildContingencyPackagesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $companyId,
        public readonly int $significantEventId,
        public readonly ?int $actorId = null,
    ) {
        $this->onQueue('siat-packages');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->companyId.':'.$this->significantEventId;
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(5)->expireAfter(330)];
    }

    public function handle(InvoicePackageService $packages): void
    {
        $event = SinSignificantEvent::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $this->companyId)
            ->findOrFail($this->significantEventId);

        if ($event->requires_manual_processing) {
            return;
        }

        $actor = $this->actorId === null
            ? null
            : User::query()->withoutGlobalScope('company')
                ->where('company_id', $this->companyId)
                ->find($this->actorId);

        $packages->buildForEvent($event, $actor)
            ->filter(fn ($package): bool => $package->package_status === InvoicePackageStatus::PendingSend)
            ->each(fn ($package) => SendContingencyPackageJob::dispatch(
                $this->companyId,
                (int) $package->id,
                $actor?->id,
            ));
    }
}
