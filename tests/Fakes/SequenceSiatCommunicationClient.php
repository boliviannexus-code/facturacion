<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Models\SinApiToken;
use App\Services\Siat\Contracts\SiatCommunicationClient;
use RuntimeException;
use Throwable;

final class SequenceSiatCommunicationClient implements SiatCommunicationClient
{
    public int $calls = 0;

    /** @param array<int, mixed> $responses */
    public function __construct(private array $responses) {}

    public function verify(SinApiToken $configuration, int $timeoutSeconds): mixed
    {
        $response = $this->responses[$this->calls] ?? new RuntimeException('No existe respuesta simulada.');
        $this->calls++;

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}
