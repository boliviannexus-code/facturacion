<?php

declare(strict_types=1);

namespace App\Services\Siat;

use App\Enums\SiatErrorType;

final readonly class SiatHealthCheckResult
{
    public bool $ok;

    public string $message;

    /**
     * @param  array<string, mixed>|null  $response
     */
    public function __construct(
        public bool $available,
        public SiatErrorType $errorType,
        public string $userMessage,
        public string $technicalMessage,
        public string $operation,
        public string $wsdlUrl,
        public int $durationMs,
        public int $requestDurationMs,
        public int $attempts,
        public bool $shouldOpenContingency,
        public string $checkedAt,
        public ?array $response = null,
    ) {
        // Alias temporales para no romper los controladores que consumian el DTO anterior.
        $this->ok = $available;
        $this->message = $userMessage;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'available' => $this->available,
            'error_type' => $this->errorType->value,
            'message' => $this->message,
            'technical_message' => $this->technicalMessage,
            'operation' => $this->operation,
            'wsdl_url' => $this->wsdlUrl,
            'duration_ms' => $this->durationMs,
            'request_duration_ms' => $this->requestDurationMs,
            'attempts' => $this->attempts,
            'should_open_contingency' => $this->shouldOpenContingency,
            'checked_at' => $this->checkedAt,
            'response' => $this->response,
        ];
    }
}
