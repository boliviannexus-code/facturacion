<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SiatScheduledProcess;
use App\Services\Siat\Monitoring\SiatScheduledProcessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RunSiatScheduledProcessJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor;

    public function __construct(public readonly SiatScheduledProcess $process)
    {
        $this->uniqueFor = max(60, (int) config('siat.monitoring.job_unique_seconds', 300));
        $this->onQueue((string) config('siat.monitoring.queue', 'siat-monitoring'));
    }

    public function uniqueId(): string
    {
        return 'siat-scheduled:'.$this->process->value;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60, 180];
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(15)->expireAfter($this->lockSeconds())];
    }

    public function handle(SiatScheduledProcessService $processes): void
    {
        $lock = Cache::lock($this->uniqueId().':distributed-lock', $this->lockSeconds());

        if (! $lock->get()) {
            return;
        }

        try {
            $processes->run($this->process);
        } finally {
            $lock->release();
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Falló un proceso programado de monitoreo SIAT.', [
            'process' => $this->process->value,
            'exception' => $exception::class,
        ]);
    }

    private function lockSeconds(): int
    {
        return max(60, (int) config('siat.monitoring.lock_seconds', 300));
    }
}
