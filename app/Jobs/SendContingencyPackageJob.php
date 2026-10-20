<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InvoicePackageStatus;
use App\Exceptions\PackageProcessingPendingException;
use App\Models\SinInvoicePackage;
use App\Models\User;
use App\Services\Billing\InvoicePackageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class SendContingencyPackageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $companyId,
        public readonly int $packageId,
        public readonly ?int $actorId = null,
    ) {
        $this->onQueue('siat-packages');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->companyId.':'.$this->packageId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return array_map(static fn (mixed $delay): int => max(0, (int) $delay), (array) config('siat.packages.send_backoff', [2, 5]));
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(5)->expireAfter(150)];
    }

    public function handle(InvoicePackageService $packages): void
    {
        $package = SinInvoicePackage::query()
            ->withoutGlobalScope('company')
            ->with('significantEvent')
            ->where('company_id', $this->companyId)
            ->findOrFail($this->packageId);

        if ($package->significantEvent?->requires_manual_processing) {
            return;
        }

        $actor = $this->actor($package);
        $result = $packages->send($package, $actor);

        if ($result->package->package_status === InvoicePackageStatus::PendingValidation) {
            CheckPackageValidationJob::dispatch($this->companyId, $this->packageId, $actor?->id);
        }

        if ($result->pending && $result->retryable) {
            throw new PackageProcessingPendingException($result->message);
        }
    }

    private function actor(SinInvoicePackage $package): ?User
    {
        if ($this->actorId === null) {
            return null;
        }

        return User::query()->withoutGlobalScope('company')
            ->where('company_id', $package->company_id)
            ->find($this->actorId);
    }
}
