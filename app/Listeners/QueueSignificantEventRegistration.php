<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ContingencyRecoveryDetected;
use App\Jobs\RegisterSignificantEventJob;

final class QueueSignificantEventRegistration
{
    public function handle(ContingencyRecoveryDetected $event): void
    {
        RegisterSignificantEventJob::dispatch(
            $event->companyId,
            $event->significantEventId,
            $event->actorId,
        );
    }
}
