<?php

declare(strict_types=1);

namespace App\Services\Siat\Recovery;

use App\Services\Siat\SiatDateTime;
use DateTimeInterface;

final readonly class SignificantEventRegistrationRequest
{
    public function __construct(
        public string $apiToken,
        public string $operationsWsdl,
        public int $environmentCode,
        public int $eventCode,
        public int $pointOfSaleCode,
        public string $systemCode,
        public int $branchCode,
        public string $currentCufd,
        public string $eventCufd,
        public string $cuis,
        public string $description,
        public DateTimeInterface $endedAt,
        public DateTimeInterface $startedAt,
        public string $taxId,
        public string $sourceTimezone = 'America/La_Paz',
    ) {}

    /** @return array{SolicitudEventoSignificativo: array<string, int|string>} */
    public function payload(): array
    {
        return [
            'SolicitudEventoSignificativo' => [
                'codigoAmbiente' => $this->environmentCode,
                'codigoMotivoEvento' => $this->eventCode,
                'codigoPuntoVenta' => $this->pointOfSaleCode,
                'codigoSistema' => $this->systemCode,
                'codigoSucursal' => $this->branchCode,
                'cufd' => $this->currentCufd,
                'cufdEvento' => $this->eventCufd,
                'cuis' => $this->cuis,
                'descripcion' => $this->description,
                'fechaHoraFinEvento' => SiatDateTime::extended($this->endedAt, $this->sourceTimezone),
                'fechaHoraInicioEvento' => SiatDateTime::extended($this->startedAt, $this->sourceTimezone),
                'nit' => $this->taxId,
            ],
        ];
    }
}
