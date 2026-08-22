<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SiatErrorType;
use RuntimeException;
use Throwable;

final class InvoiceTransportException extends RuntimeException
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
