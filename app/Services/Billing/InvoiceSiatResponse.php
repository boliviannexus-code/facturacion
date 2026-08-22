<?php

declare(strict_types=1);

namespace App\Services\Billing;

final readonly class InvoiceSiatResponse
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public array $data,
        public int $durationMs,
    ) {}
}
