<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\Siat\Contracts\SiatDelay;

final class RecordingSiatDelay implements SiatDelay
{
    /** @var array<int, int> */
    public array $waits = [];

    public function wait(int $seconds): void
    {
        $this->waits[] = $seconds;
    }
}
