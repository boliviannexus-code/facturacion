<?php

declare(strict_types=1);

namespace App\Services\Siat;

use App\Enums\SiatErrorType;

final readonly class SiatRetryPolicy
{
    /**
     * @param  array<int, int>  $delays
     */
    public function __construct(
        private array $delays = [0, 2, 5],
        public int $timeoutSeconds = 5,
    ) {}

    /** @return array<int, int> */
    public function delays(): array
    {
        $delays = array_values(array_map(
            static fn (mixed $delay): int => max(0, (int) $delay),
            $this->delays,
        ));

        return $delays === [] ? [0] : $delays;
    }

    public function shouldRetry(SiatErrorType $errorType, int $attempt): bool
    {
        return $errorType->isRetryable() && $attempt < count($this->delays());
    }
}
