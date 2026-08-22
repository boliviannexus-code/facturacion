<?php

declare(strict_types=1);

namespace App\Services\Billing\Packages;

use App\Enums\PackageValidationOutcome;

final readonly class PackageValidationResult
{
    /**
     * @param  array<string, mixed>  $response
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, PackageInvoiceValidationResult>  $invoiceResults
     */
    public function __construct(
        public PackageValidationOutcome $outcome,
        public ?int $statusCode,
        public string $message,
        public array $response,
        public array $messages,
        public array $invoiceResults,
        public int $durationMs,
    ) {}
}
