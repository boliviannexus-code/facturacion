<?php

namespace App\Services\Siat;

use Illuminate\Support\Carbon;

final readonly class SiatCommunicationResult
{
    public function __construct(
        public bool $ok,
        public string $message,
        public string $operation,
        public string $wsdlUrl,
        public int $durationMs,
        public string $checkedAt,
        public ?array $response = null,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     operation: string,
     *     wsdl_url: string,
     *     duration_ms: int,
     *     checked_at: string,
     *     response: array<string, mixed>|null
     * }
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'operation' => $this->operation,
            'wsdl_url' => $this->wsdlUrl,
            'duration_ms' => $this->durationMs,
            'checked_at' => $this->checkedAt,
            'response' => $this->response,
        ];
    }

    public static function failed(string $message, string $wsdlUrl, int $durationMs = 0): self
    {
        return new self(
            ok: false,
            message: $message,
            operation: 'verificarComunicacion',
            wsdlUrl: $wsdlUrl,
            durationMs: $durationMs,
            checkedAt: Carbon::now()->format('d/m/Y H:i:s'),
        );
    }
}
