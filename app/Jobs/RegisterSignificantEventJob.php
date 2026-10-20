<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ContingencyRecoveryPendingException;
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

final class RegisterSignificantEventJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

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
        return array_map(
            static fn (mixed $delay): int => max(0, (int) $delay),
            (array) config('siat.contingency_recovery.registration_backoff', [2, 5]),
        );
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(5)
                ->expireAfter(90),
        ];
    }

    public function handle(ContingencyRecoveryService $recovery): void
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
        $result = $recovery->registerRecoveredEvent($event, $actor);

        if ($result->pending && $result->retryable) {
            throw new ContingencyRecoveryPendingException($result->message);
        }
    }
}
