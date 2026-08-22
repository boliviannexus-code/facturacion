<?php

declare(strict_types=1);

namespace App\Services\Siat\Recovery;

use App\Models\SinCufd;

final readonly class CufdAcquisitionResult
{
    public function __construct(
        public bool $successful,
        public ?SinCufd $cufd,
        public string $message,
    ) {}
}
