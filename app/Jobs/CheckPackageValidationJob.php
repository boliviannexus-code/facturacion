<?php

declare(strict_types=1);

namespace App\Jobs;

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

final class CheckPackageValidationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

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
        return array_map(static fn (mixed $delay): int => max(0, (int) $delay), (array) config('siat.packages.validation_backoff', [5, 15, 30, 60]));
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

        $actor = $this->actorId === null
            ? null
            : User::query()->withoutGlobalScope('company')
                ->where('company_id', $this->companyId)
                ->find($this->actorId);
        $result = $packages->checkValidation($package, $actor);

        if ($result->pending && $result->retryable) {
            throw new PackageProcessingPendingException($result->message);
        }
    }
}
