<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\InvoiceEmissionMode;
use App\Enums\SignificantEventStatus;
use App\Events\SignificantEventRegistered;
use App\Jobs\BuildContingencyPackagesJob;
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

        if (in_array((int) $significantEvent->event_code, [5, 6, 7], true)
            && ($significantEvent->invoiceIssue === null || $significantEvent->invoiceIssue->emission_mode === InvoiceEmissionMode::ManualCafc)
            && ! $significantEvent->invoiceIssues()->where('emission_mode', '<>', InvoiceEmissionMode::ManualCafc)->exists()) {
            return;
        }

        BuildContingencyPackagesJob::dispatch(
            $event->companyId,
            $event->significantEventId,
            $significantEvent->registered_by_user_id ?? $significantEvent->user_id,
        );

    }
}
