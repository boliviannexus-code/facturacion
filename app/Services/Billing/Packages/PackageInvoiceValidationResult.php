<?php

declare(strict_types=1);

namespace App\Services\Billing\Packages;

use App\Enums\PackageValidationOutcome;

final readonly class PackageInvoiceValidationResult
{
    /** @param array<string, mixed> $rawData */
    public function __construct(
        public string $cuf,
        public PackageValidationOutcome $outcome,
        public ?int $statusCode,
        public string $message,
        public array $rawData = [],
    ) {}
}
