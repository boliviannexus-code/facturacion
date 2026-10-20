<?php

declare(strict_types=1);

namespace App\Services\Siat;

use App\Models\SinSignificantEvent;

final readonly class ContingencyRecoveryResult
{
    public function __construct(
        public SinSignificantEvent $event,
        public bool $recoveryDetected,
        public bool $registered,
        public bool $pending,
        public bool $retryable,
        public string $message,
    ) {}
}
