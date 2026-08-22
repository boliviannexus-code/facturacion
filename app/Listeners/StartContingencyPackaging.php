<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ManualContingencyInvoiceStatus;
use App\Enums\SignificantEventStatus;
use App\Events\SignificantEventRegistered;
use App\Jobs\BuildContingencyPackagesJob;
use App\Jobs\SendManualCafcInvoiceJob;
use App\Models\SinSignificantEvent;

final class StartContingencyPackaging
{
    public function handle(SignificantEventRegistered $event): void
    {
        $significantEvent = SinSignificantEvent::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $event->companyId)
            ->find($event->significantEventId);

        if (! $significantEvent || $significantEvent->event_status !== SignificantEventStatus::Registered) {
            return;
        }

        BuildContingencyPackagesJob::dispatch(
            $event->companyId,
            $event->significantEventId,
            $significantEvent->registered_by_user_id ?? $significantEvent->user_id,
        );

        $significantEvent->manualInvoices()
            ->where('manual_status', ManualContingencyInvoiceStatus::PendingSend)
            ->pluck('id')
            ->each(fn (int $manualId) => SendManualCafcInvoiceJob::dispatch(
                $event->companyId,
                $manualId,
                $significantEvent->registered_by_user_id ?? $significantEvent->user_id,
            ));
    }
}
