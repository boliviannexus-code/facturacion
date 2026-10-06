<?php

declare(strict_types=1);

namespace App\Services\Siat;

use App\Enums\SiatErrorType;

final readonly class SiatContingencyPolicy
{
    public function __construct(private int $minimumConsecutiveFailures = 3) {}

    /** @param array<int, SiatErrorType> $attemptErrors */
    public function shouldOpen(array $attemptErrors): bool
    {
        if (count($attemptErrors) < max(2, $this->minimumConsecutiveFailures)) {
            return false;
        }

        foreach ($attemptErrors as $errorType) {
            if (! $errorType->canOpenContingencyAfterRetries()) {
                return false;
            }
        }

        return true;
    }
}
