<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Siat\Recovery\SignificantEventRegistrationRequest;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SignificantEventRegistrationRequestTest extends TestCase
{
    public function test_payload_matches_siat_significant_event_contract(): void
    {
        $request = new SignificantEventRegistrationRequest(
            apiToken: 'delegated-token',
            operationsWsdl: 'https://siat.example/v2/FacturacionOperaciones?wsdl',
            environmentCode: 1,
            eventCode: 3,
            pointOfSaleCode: 2,
            systemCode: 'SYS-1',
            branchCode: 1,
            currentCufd: 'CUFD-NUEVO',
            eventCufd: 'CUFD-CONTINGENCIA',
            cuis: 'CUIS-1',
            description: 'Corte de Internet.',
            endedAt: new DateTimeImmutable('2026-08-04 11:30:45.456-04:00'),
            startedAt: new DateTimeImmutable('2026-08-04 10:00:01.123-04:00'),
            taxId: '123456789',
        );

        $payload = $request->payload()['SolicitudEventoSignificativo'];

        self::assertSame(1, $payload['codigoAmbiente']);
        self::assertSame(3, $payload['codigoMotivoEvento']);
        self::assertSame('CUFD-NUEVO', $payload['cufd']);
        self::assertSame('CUFD-CONTINGENCIA', $payload['cufdEvento']);
        self::assertSame('2026-08-04T10:00:01.123', $payload['fechaHoraInicioEvento']);
        self::assertSame('2026-08-04T11:30:45.456', $payload['fechaHoraFinEvento']);
        self::assertArrayNotHasKey('codigoEvento', $payload);
        self::assertArrayNotHasKey('fechaInicioEvento', $payload);
    }

    public function test_payload_converts_a_utc_instant_to_the_bolivia_utc_extended_wall_clock(): void
    {
        $request = new SignificantEventRegistrationRequest(
            apiToken: 'delegated-token',
            operationsWsdl: 'https://siat.example/v2/FacturacionOperaciones?wsdl',
            environmentCode: 1,
            eventCode: 2,
            pointOfSaleCode: 1,
            systemCode: 'SYS-1',
            branchCode: 0,
            currentCufd: 'CUFD-NUEVO',
            eventCufd: 'CUFD-CONTINGENCIA',
            cuis: 'CUIS-1',
            description: 'Inaccesibilidad al servicio web del SIN.',
            endedAt: new DateTimeImmutable('2026-08-13 13:01:47+00:00'),
            startedAt: new DateTimeImmutable('2026-08-13 12:52:17+00:00'),
            taxId: '123456789',
        );

        $payload = $request->payload()['SolicitudEventoSignificativo'];

        self::assertSame('2026-08-13T08:52:17.000', $payload['fechaHoraInicioEvento']);
        self::assertSame('2026-08-13T09:01:47.000', $payload['fechaHoraFinEvento']);
    }
}
