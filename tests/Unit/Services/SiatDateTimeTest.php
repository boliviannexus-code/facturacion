<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Siat\SiatDateTime;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SiatDateTimeTest extends TestCase
{
    public function test_extended_format_uses_bolivia_wall_clock_without_an_offset(): void
    {
        $utc = new DateTimeImmutable('2026-08-13 13:36:45.123+00:00');

        self::assertSame(
            '2026-08-13T09:36:45.123',
            SiatDateTime::extended($utc, 'America/La_Paz'),
        );
    }

    public function test_local_iso_keeps_the_bolivia_offset_for_browser_date_math(): void
    {
        $utc = new DateTimeImmutable('2026-08-13 13:36:45+00:00');

        self::assertSame(
            '2026-08-13T09:36:45-04:00',
            SiatDateTime::localIso($utc, 'America/La_Paz'),
        );
    }
}
