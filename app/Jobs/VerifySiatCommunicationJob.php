<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SinApiToken;
use App\Models\SinPointOfSale;
use App\Models\User;
use App\Services\Siat\SiatCommunicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class VerifySiatCommunicationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor;

    public function __construct(
        public readonly int $companyId,
        public readonly int $apiTokenId,
        public readonly ?int $pointOfSaleId = null,
        public readonly ?int $userId = null,
    ) {
        $this->uniqueFor = (int) config('siat.communication.job_unique_seconds', 60);
        $this->onQueue('siat-health');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return implode(':', [$this->companyId, $this->apiTokenId, $this->pointOfSaleId ?? 0]);
    }

    public function handle(SiatCommunicationService $communication): void
    {
        $token = SinApiToken::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $this->companyId)
            ->findOrFail($this->apiTokenId);
        $pointOfSale = $this->pointOfSaleId === null ? null : SinPointOfSale::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $this->companyId)
            ->findOrFail($this->pointOfSaleId);
        $user = $this->userId === null ? null : User::query()
            ->where('company_id', $this->companyId)
            ->findOrFail($this->userId);

        $communication->verify($token, $pointOfSale, $user);
    }
}
