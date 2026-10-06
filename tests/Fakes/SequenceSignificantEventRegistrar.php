<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\Siat\Recovery\Contracts\SignificantEventRegistrar;
use App\Services\Siat\Recovery\SignificantEventRegistrationRequest;
use App\Services\Siat\Recovery\SignificantEventRegistrationResult;
use RuntimeException;
use Throwable;

final class SequenceSignificantEventRegistrar implements SignificantEventRegistrar
{
    public int $calls = 0;

    /** @var array<int, SignificantEventRegistrationRequest> */
    public array $requests = [];

    /** @param array<int, SignificantEventRegistrationResult|Throwable> $results */
    public function __construct(private array $results) {}

    public function register(SignificantEventRegistrationRequest $request): SignificantEventRegistrationResult
    {
        $this->calls++;
        $this->requests[] = $request;
        $result = array_shift($this->results)
            ?? throw new RuntimeException('No existe una respuesta de registro simulada.');

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}
