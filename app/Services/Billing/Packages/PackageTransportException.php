<?php

declare(strict_types=1);

namespace App\Services\Billing\Packages;

use App\Enums\SiatErrorType;
use RuntimeException;
use Throwable;

final class PackageTransportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $mayHaveReachedSiat,
        public readonly SiatErrorType $errorType,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
