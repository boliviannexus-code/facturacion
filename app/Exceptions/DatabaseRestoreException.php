<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class DatabaseRestoreException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $safetyRecovered,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
