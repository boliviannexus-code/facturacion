<?php

declare(strict_types=1);

namespace App\Services\Siat;

final readonly class PointOfSaleSyncResult
{
    public function __construct(
        public int $received,
        public int $created,
        public int $updated,
    ) {}
}
