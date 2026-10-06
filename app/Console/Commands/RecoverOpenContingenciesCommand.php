<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SignificantEventStatus;
use App\Models\SinSignificantEvent;
use App\Models\User;
use App\Services\Siat\ContingencyRecoveryService;
use Illuminate\Console\Command;
use Throwable;

final class RecoverOpenContingenciesCommand extends Command
{
    protected $signature = 'siat:recover-open-contingencies
        {--company= : Procesar solamente una empresa}
        {--event= : Procesar solamente un evento significativo}
        {--actor= : Usuario responsable del proceso o correccion}
        {--event-code= : Correccion administrativa del codigo de evento}
        {--description= : Correccion administrativa de la descripcion}
        {--reason= : Motivo obligatorio de la correccion administrativa}';

    protected $description = 'Detecta la recuperacion de SIAT y reanuda el registro de contingencias abiertas';

    public function handle(ContingencyRecoveryService $recovery): int
    {
        $statuses = [
            SignificantEventStatus::Open,
            SignificantEventStatus::RecoveryDetected,
            SignificantEventStatus::PendingRegistration,
        ];

        if ($this->option('event') !== null
            && ($this->option('event-code') !== null || $this->option('description') !== null)) {
            $statuses[] = SignificantEventStatus::Failed;
        }

        $query = SinSignificantEvent::query()
            ->withoutGlobalScope('company')
            ->whereNull('closed_at')
            ->whereIn('event_status', $statuses)
            ->orderBy('company_id')
            ->orderBy('id');

        if ($this->option('company') !== null) {
            $query->where('company_id', (int) $this->option('company'));
        }

        if ($this->option('event') !== null) {
            $query->whereKey((int) $this->option('event'));
        } else {
            $query->where('requires_manual_processing', false);
        }

        $events = $query->get();

        if ($events->isEmpty()) {
            $this->info('No existen contingencias abiertas para procesar.');

            return self::SUCCESS;
        }

        $processed = 0;
        $pending = 0;

        foreach ($events as $event) {
            try {
                $actor = $this->actorFor($event);
                $this->applyAdministrativeCorrectionWhenRequested($recovery, $event, $actor);
                $result = $recovery->detectRecovery($event, $actor);
                $processed++;
                $pending += $result->pending ? 1 : 0;
                $this->line("Evento {$event->id}: {$result->message}");
            } catch (Throwable $exception) {
                $pending++;
                $this->error("Evento {$event->id}: {$exception->getMessage()}");
            }
        }

        $this->info("Eventos procesados: {$processed}. Pendientes: {$pending}.");

        return self::SUCCESS;
    }

    private function actorFor(SinSignificantEvent $event): ?User
    {
        $actorId = $this->option('actor') !== null
            ? (int) $this->option('actor')
            : $event->user_id;

        if (! $actorId) {
            return null;
        }

        return User::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $event->company_id)
            ->find($actorId);
    }

    private function applyAdministrativeCorrectionWhenRequested(
        ContingencyRecoveryService $recovery,
        SinSignificantEvent $event,
        ?User $actor,
    ): void {
        $code = $this->option('event-code');
        $description = $this->option('description');

        if ($code === null && $description === null) {
            return;
        }

        if ($actor === null || blank($this->option('reason'))) {
            throw new \DomainException('La correccion administrativa requiere --actor y --reason.');
        }

        $recovery->correctManually(
            $event,
            $actor,
            (string) $this->option('reason'),
            $code !== null ? (int) $code : null,
            $description !== null ? (string) $description : null,
        );
    }
}
