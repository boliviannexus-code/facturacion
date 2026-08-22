<?php

declare(strict_types=1);

namespace App\Services\Siat;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class SiatDateTime
{
    public static function extended(DateTimeInterface $date, ?string $timezone = null): string
    {
        return CarbonImmutable::instance($date)
            ->setTimezone($timezone ?? config('app.timezone', 'America/La_Paz'))
            ->format('Y-m-d\TH:i:s.v');
    }

    public static function localIso(DateTimeInterface $date, ?string $timezone = null): string
    {
        return CarbonImmutable::instance($date)
            ->setTimezone($timezone ?? config('app.timezone', 'America/La_Paz'))
            ->toIso8601String();
    }
}
