<?php

declare(strict_types=1);

namespace App\Services\Billing\Packages;

use App\Enums\PackageValidationOutcome;

final readonly class SiatPackageStatusMapper
{
    public function outcome(?int $statusCode): PackageValidationOutcome
    {
        if ($statusCode !== null && in_array($statusCode, $this->codes('validated'), true)) {
            return PackageValidationOutcome::Validated;
        }

        if ($statusCode !== null && in_array($statusCode, $this->codes('observed'), true)) {
            return PackageValidationOutcome::Observed;
        }

        if ($statusCode !== null && in_array($statusCode, $this->codes('rejected'), true)) {
            return PackageValidationOutcome::Rejected;
        }

        return PackageValidationOutcome::Pending;
    }

    /** @return array<int, int> */
    private function codes(string $outcome): array
    {
        return array_map(
            static fn (mixed $code): int => (int) $code,
            (array) config("siat.packages.validation_status_codes.{$outcome}", []),
        );
    }
}
