<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SignificantEventStatus;
use App\Models\SinCafcRange;
use App\Models\SinManualContingencyInvoice;
use App\Models\SinSignificantEvent;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

final class ManualCafcCompliance
{
    public function validate(SinCafcRange $range, ?SinSignificantEvent $event, DateTimeInterface $issuedAt, bool $acceptProcessing = false): void
    {
        $statuses = $acceptProcessing
            ? [SignificantEventStatus::Registered, SignificantEventStatus::Packaging, SignificantEventStatus::Sending, SignificantEventStatus::Validating]
            : [SignificantEventStatus::Registered];
        if ($range->trashed() || ! $event || ! $event->transaccion || blank($event->reception_code)
            || ! in_array($event->event_status, $statuses, true)) {
            $this->fail('significant_event_id', 'Primero registre el evento ante el SIN y obtenga su código de recepción.');
        }
        if (! in_array((int) $event->event_code, [5, 6, 7], true)
            || (int) $range->company_id !== (int) $event->company_id
            || (int) $range->sin_branch_id !== (int) $event->sin_branch_id
            || (int) $range->sin_point_of_sale_id !== (int) $event->sin_point_of_sale_id
            || (int) $range->sin_significant_event_id !== (int) $event->id) {
            $this->fail('significant_event_id', 'El evento 5, 6 o 7 debe pertenecer al CAFC, empresa, sucursal y punto de venta seleccionados.');
        }
        $date = CarbonImmutable::instance($issuedAt);
        if (! $event->started_at || ! $event->ended_at || $event->ended_at->lte($event->started_at)
            || $event->ended_at->isFuture() || $date->lt($event->started_at) || $date->gt($event->ended_at)) {
            $this->fail('issued_manually_at', 'La fecha y hora original de la factura debe estar dentro del período registrado del evento.');
        }
        if (now()->gt($event->ended_at->addHours(72))) {
            $this->fail('significant_event_id', 'Venció el plazo de 72 horas desde el fin de la contingencia para regularizar las facturas manuales.');
        }
        $cufd = $event->cufd;
        if (! $cufd || ! $cufd->transaccion || blank($cufd->cufd_code) || blank($cufd->control_code)
            || (int) $cufd->company_id !== (int) $event->company_id
            || (int) $cufd->sin_point_of_sale_id !== (int) $event->sin_point_of_sale_id
            || ! $cufd->requested_at || ! $cufd->expires_at
            || $cufd->requested_at->gt($event->started_at) || $cufd->expires_at->lte($event->started_at)
            || ($cufd->invalidated_at && $cufd->invalidated_at->lte($event->started_at))) {
            $this->fail('issued_manually_at', 'El CUFD del evento debe haber estado vigente al inicio de la contingencia y tener un código de control válido.');
        }
    }

    public function validateTranscription(SinManualContingencyInvoice $manual): void
    {
        $this->validate($manual->cafcRange, $manual->significantEvent, $manual->issued_manually_at);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
