<?php

declare(strict_types=1);

namespace App\Services\Siat\Contracts;

interface SiatDelay
{
    public function wait(int $seconds): void;
}
