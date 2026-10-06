<?php

declare(strict_types=1);

namespace App\Services\Siat\Recovery;

final readonly class SignificantEventRegistrationResult
{
    /**
     * @param  array<string, mixed>  $response
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function __construct(
        public bool $successful,
        public ?string $receptionCode,
        public string $message,
        public array $response,
        public array $messages,
        public int $durationMs,
    ) {}
}
