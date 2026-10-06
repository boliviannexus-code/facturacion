<?php

declare(strict_types=1);

namespace App\Services\Billing\Packages;

final readonly class PackageReceptionResult
{
    /**
     * @param  array<string, mixed>  $response
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function __construct(
        public bool $accepted,
        public ?string $receptionCode,
        public ?int $statusCode,
        public string $message,
        public array $response,
        public array $messages,
        public int $durationMs,
    ) {}
}
