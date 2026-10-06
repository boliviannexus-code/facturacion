<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SinSignificantEvent;
use App\Models\User;
use App\Services\Siat\ContingencyRecoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DetectContingencyRecoveryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $companyId,
        public readonly int $significantEventId,
        public readonly ?int $actorId = null,
    ) {
        $this->onQueue('siat-recovery');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->companyId.':'.$this->significantEventId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('siat-recovery-detect:'.$this->uniqueId()))->releaseAfter(10)->expireAfter(120)];
    }

    public function handle(ContingencyRecoveryService $recovery): void
    {
        $event = SinSignificantEvent::query()->withoutGlobalScope('company')
            ->where('company_id', $this->companyId)->findOrFail($this->significantEventId);

        if ($event->requires_manual_processing) {
            return;
        }

        $actor = $this->actorId === null ? null : User::query()->withoutGlobalScope('company')
            ->where('company_id', $this->companyId)->find($this->actorId);

        $recovery->detectRecovery($event, $actor);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Falló la detección de recuperación SIAT.', [
            'company_id' => $this->companyId,
            'event_id' => $this->significantEventId,
            'exception' => $exception::class,
        ]);
    }
}
