<?php

declare(strict_types=1);

namespace App\Services\Siat;

use App\Services\Siat\Contracts\SiatDelay;

final class NativeSiatDelay implements SiatDelay
{
    public function wait(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
