<?php

declare(strict_types=1);

namespace App\Services\Siat;

use App\Enums\SiatAttemptStatus;
use App\Enums\SiatEnvironment;
use App\Enums\SiatMessageSeverity;
use App\Enums\SiatOperation;
use App\Enums\SignificantEventStatus;
use App\Events\ContingencyRecoveryDetected;
use App\Events\SignificantEventRegistered;
use App\Jobs\RegisterSignificantEventJob;
use App\Models\SinCatalogItem;
use App\Models\SinResponseMessage;
use App\Models\SinSiatAttempt;
use App\Models\SinSignificantEvent;
use App\Models\User;
use App\Services\Siat\Recovery\Contracts\RecoveryCufdProvider;
use App\Services\Siat\Recovery\Contracts\SignificantEventRegistrar;
use App\Services\Siat\Recovery\SignificantEventRegistrationRequest;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OwenIt\Auditing\Models\Audit;
use Throwable;

final class ContingencyRecoveryService
{
    public function __construct(
        private readonly SiatCommunicationService $communication,
        private readonly RecoveryCufdProvider $cufdProvider,
        private readonly SignificantEventRegistrar $registrar,
        private readonly SiatErrorClassifier $errorClassifier,
        private readonly SiatLogSanitizer $sanitizer,
    ) {}

    public function prepareAndDetectRecovery(
        SinSignificantEvent $significantEvent,
        User $actor,
        int $eventCode,
        string $description,
    ): ContingencyRecoveryResult {
        $event = $this->eventForCompany($significantEvent);
        $actor = $this->safeActor($event, $actor) ?? $event->creator;

        if (! $actor) {
            throw new DomainException('No existe un usuario de la empresa para registrar el evento significativo.');
        }

        $catalogEventExists = SinCatalogItem::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $event->company_id)
            ->where('catalog_key', 'eventos_significativos')
            ->where('classifier_code', (string) $eventCode)
            ->where('is_active', true)
            ->exists();

        if (! $catalogEventExists) {
            throw new DomainException('El evento seleccionado no pertenece al catálogo vigente de Impuestos.');
        }

        $event = DB::transaction(function () use ($event, $actor, $eventCode, $description): SinSignificantEvent {
            $locked = SinSignificantEvent::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $event->company_id)
                ->lockForUpdate()
                ->findOrFail($event->id);

            if (! in_array($locked->event_status, [
                SignificantEventStatus::Open,
                SignificantEventStatus::RecoveryDetected,
                SignificantEventStatus::PendingRegistration,
                SignificantEventStatus::Failed,
            ], true) || $locked->transaccion || $locked->registration_claim !== null) {
                throw new DomainException('El evento ya fue aceptado o está siendo registrado y no puede cambiar de tipo.');
            }

            $locked->update([
                'event_code' => $eventCode,
                'event_description' => trim($description),
                'updated_by_user_id' => $actor->id,
                'status_label' => 'Pendiente de registro',
                'message' => 'Evento fiscal seleccionado; pendiente de comunicación con SIAT.',
                'requires_manual_processing' => false,
            ]);

            return $locked->refresh();
        }, 3);

        if ($event->event_status === SignificantEventStatus::Open) {
            $detected = $this->detectRecovery($event, $actor, dispatchRegistration: false);

            if (! $detected->recoveryDetected) {
                return $detected;
            }

            $event = $detected->event;
        }

        // La acción iniciada por el operador debe comunicar el evento al SIAT
        // inmediatamente. La cola queda como mecanismo de recuperación, no como
        // requisito para obtener la confirmación del registro manual.
        return $this->registerRecoveredEvent($event, $actor);
    }

    public function detectRecovery(
        SinSignificantEvent $significantEvent,
        ?User $actor = null,
        bool $dispatchRegistration = true,
    ): ContingencyRecoveryResult {
        $event = $this->eventForCompany($significantEvent);
        $actor = $this->safeActor($event, $actor);

        if ($this->isTerminal($event)) {
            return $this->result($event, 'El evento ya fue registrado o cerrado.', registered: $this->isRegistered($event));
        }

        if (in_array($event->event_status, [
            SignificantEventStatus::RecoveryDetected,
            SignificantEventStatus::PendingRegistration,
        ], true)) {
            if ($dispatchRegistration) {
                RegisterSignificantEventJob::dispatch((int) $event->company_id, (int) $event->id, $actor?->id);
            }

            return $this->result(
                $event,
                $dispatchRegistration
                    ? 'La recuperacion ya fue detectada; se reanudo el registro.'
                    : 'La recuperacion ya fue detectada; el registro se procesara inmediatamente.',
                detected: true,
                pending: true,
            );
        }

        $event->loadMissing(['apiToken', 'pointOfSale.branch']);

        if (! $event->apiToken || ! $event->pointOfSale) {
            return $this->markForReview($event, $actor, 'Falta el token o el punto de venta requerido para verificar la recuperacion.');
        }

        $health = $this->communication->verify($event->apiToken, $event->pointOfSale, $actor);

        if (! $health->ok) {
            return $this->result(
                $event,
                'La comunicacion con SIAT aun no se ha recuperado: '.$health->message,
                pending: true,
            );
        }

        $recoveredAt = now();
        $event = DB::transaction(function () use ($event, $actor, $recoveredAt): SinSignificantEvent {
            $locked = SinSignificantEvent::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $event->company_id)
                ->lockForUpdate()
                ->findOrFail($event->id);

            if ($locked->event_status === SignificantEventStatus::Open) {
                $minimumRecoveredAt = $locked->started_at->addSecond();
                $effectiveRecoveredAt = $recoveredAt->lessThan($minimumRecoveredAt)
                    ? $minimumRecoveredAt
                    : $recoveredAt;
                $locked->update([
                    'event_status' => SignificantEventStatus::RecoveryDetected,
                    'ended_at' => $effectiveRecoveredAt,
                    'recovery_detected_at' => $effectiveRecoveredAt,
                    'updated_by_user_id' => $actor?->id,
                    'status_label' => 'Recuperacion detectada',
                    'message' => 'La comunicacion con SIAT fue restablecida.',
                ]);
            }

            return $locked->refresh();
        }, 3);

        if ($dispatchRegistration) {
            ContingencyRecoveryDetected::dispatch(
                (int) $event->company_id,
                (int) $event->id,
                $actor?->id,
            );
        }

        return $this->result(
            $event,
            'Recuperacion detectada y registro del evento encolado.',
            detected: true,
            pending: true,
        );
    }

    public function registerRecoveredEvent(
        SinSignificantEvent $significantEvent,
        ?User $actor = null,
    ): ContingencyRecoveryResult {
        $event = $this->eventForCompany($significantEvent);
        $actor = $this->safeActor($event, $actor) ?? $event->creator;

        if ($this->isRegistered($event)) {
            return $this->result($event, 'El evento ya se encuentra registrado.', detected: true, registered: true);
        }

        if ($event->closed_at !== null || in_array($event->event_status, [
            SignificantEventStatus::Completed,
            SignificantEventStatus::Expired,
        ], true)) {
            return $this->result($event, 'El evento esta cerrado y no puede volver a registrarse.');
        }

        if (! in_array($event->event_status, [
            SignificantEventStatus::RecoveryDetected,
            SignificantEventStatus::PendingRegistration,
            SignificantEventStatus::Failed,
        ], true) || $event->recovery_detected_at === null || $event->ended_at === null) {
            return $this->result($event, 'Primero debe detectarse la recuperacion de la comunicacion.', pending: true);
        }

        $event->loadMissing([
            'creator', 'apiToken', 'authorization', 'branch', 'pointOfSale.branch',
            'cuis', 'cufd', 'recoveryCufd',
        ]);

        if (! $actor || ! $event->pointOfSale || ! $event->apiToken || ! $event->authorization || ! $event->cuis) {
            return $this->markForReview(
                $event,
                $actor,
                'No existe un usuario de la empresa o punto de venta para solicitar el nuevo CUFD.',
                retryable: false,
            );
        }

        $recoveryCufd = $event->recoveryCufd;

        if (! $recoveryCufd?->transaccion || blank($recoveryCufd->cufd_code) || $recoveryCufd->expires_at?->isFuture() !== true) {
            $cufdResult = $this->cufdProvider->acquire(
                $actor,
                $event->pointOfSale,
                $event->apiToken,
                $event->authorization,
                $event->cuis,
            );

            if (! $cufdResult->successful || ! $cufdResult->cufd) {
                return $this->pending(
                    $event,
                    $actor,
                    'No se pudo obtener el nuevo CUFD: '.$cufdResult->message,
                    retryable: true,
                );
            }

            if ((int) $cufdResult->cufd->company_id !== (int) $event->company_id
                || (int) $cufdResult->cufd->sin_point_of_sale_id !== (int) $event->sin_point_of_sale_id) {
                return $this->markForReview(
                    $event,
                    $actor,
                    'El CUFD obtenido no corresponde a la empresa y punto de venta del evento.',
                    retryable: false,
                );
            }

            $event->update([
                'recovery_sin_cufd_id' => $cufdResult->cufd->id,
                'updated_by_user_id' => $actor->id,
                'event_status' => SignificantEventStatus::PendingRegistration,
                'status_label' => 'Pendiente de registro',
                'message' => $cufdResult->message,
            ]);
            $event->setRelation('recoveryCufd', $cufdResult->cufd);
            $recoveryCufd = $cufdResult->cufd;
        }

        if (! $event->apiToken || ! $event->authorization || ! $event->branch
            || ! $event->cuis || ! $event->cufd || ! $recoveryCufd) {
            return $this->markForReview(
                $event,
                $actor,
                'La configuracion fiscal historica del evento esta incompleta.',
                retryable: false,
            );
        }

        if ($event->authorization->environment_code === SiatEnvironment::Production
            && str_contains(mb_strtolower((string) $event->apiToken->wsdl_url), 'pilotosiat')) {
            return $this->markForReview(
                $event,
                $actor,
                'Registro bloqueado: la autorización está en Producción, pero el WSDL configurado pertenece al ambiente Piloto.',
                retryable: false,
            );
        }

        $request = new SignificantEventRegistrationRequest(
            apiToken: (string) $event->apiToken->api_token,
            operationsWsdl: SiatWsdlRegistry::relatedService(
                (string) $event->apiToken->wsdl_url,
                'FacturacionOperaciones',
            ),
            environmentCode: (int) $event->authorization->environment_code->value,
            eventCode: (int) $event->event_code,
            pointOfSaleCode: (int) $event->pointOfSale->point_of_sale_code,
            systemCode: (string) $event->authorization->system_code,
            branchCode: (int) $event->branch->branch_code,
            currentCufd: (string) $recoveryCufd->cufd_code,
            eventCufd: (string) $event->cufd->cufd_code,
            cuis: (string) $event->cuis->cuis_code,
            description: (string) $event->event_description,
            endedAt: $event->ended_at,
            startedAt: $event->started_at,
            taxId: (string) $event->authorization->tax_id,
            sourceTimezone: config('app.timezone', 'America/La_Paz'),
        );
        $claim = (string) Str::uuid();
        $attempt = $this->claimRegistration($event, $actor, $request, $claim);

        if (! $attempt) {
            $current = $this->eventForCompany($event);

            return $this->result(
                $current,
                $this->isRegistered($current)
                    ? 'El evento ya se encuentra registrado.'
                    : 'Otro proceso ya esta registrando este evento.',
                detected: true,
                registered: $this->isRegistered($current),
                pending: ! $this->isRegistered($current),
            );
        }

        try {
            $registration = $this->registrar->register($request);
            $safeResponse = $this->sanitizer->data($registration->response, $request->apiToken) ?? [];
            $safeMessages = $this->sanitizer->data(
                ['messages' => $registration->messages],
                $request->apiToken,
            )['messages'] ?? [];
            $safeMessage = $this->sanitizer->text($registration->message, $request->apiToken)
                ?: 'SIAT no devolvio un mensaje.';
            $requiresManualReview = ! $registration->successful
                && collect($registration->messages)->contains(
                    static fn (array $message): bool => in_array((int) ($message['codigo'] ?? 0), [914, 984], true),
                );

            $event = DB::transaction(function () use (
                $event,
                $actor,
                $attempt,
                $claim,
                $registration,
                $safeResponse,
                $safeMessages,
                $safeMessage,
                $requiresManualReview,
            ): SinSignificantEvent {
                $locked = SinSignificantEvent::query()
                    ->withoutGlobalScope('company')
                    ->where('company_id', $event->company_id)
                    ->lockForUpdate()
                    ->findOrFail($event->id);

                if ($this->isRegistered($locked)) {
                    return $locked;
                }

                if ($locked->registration_claim !== $claim) {
                    throw new DomainException('El registro del evento perdio su claim de idempotencia.');
                }

                $finishedAt = $this->attemptFinishedAt($attempt);
                $durationMs = max(0, $registration->durationMs);
                $attempt->update([
                    'attempt_status' => $registration->successful
                        ? SiatAttemptStatus::Succeeded
                        : SiatAttemptStatus::Failed,
                    'failure_category' => $registration->successful
                        ? null
                        : $this->errorClassifier->classifyResponse($registration->response)->failureCategory(),
                    'reception_code' => $registration->receptionCode,
                    'duration_ms' => $durationMs,
                    'message' => $safeMessage,
                    'response' => $safeResponse,
                    'finished_at' => $finishedAt,
                ]);
                $this->storeMessages($attempt, $safeMessages, $safeMessage, $registration->successful);

                $locked->update([
                    'event_status' => $registration->successful
                        ? SignificantEventStatus::Registered
                        : ($requiresManualReview ? SignificantEventStatus::Failed : SignificantEventStatus::PendingRegistration),
                    'reception_code' => $registration->successful ? $registration->receptionCode : null,
                    'transaccion' => $registration->successful,
                    'status_label' => $registration->successful
                        ? 'Registrado'
                        : ($requiresManualReview ? 'Revisión manual requerida' : 'Pendiente de reintento'),
                    'response' => $safeResponse,
                    'message' => $safeMessage,
                    'duration_ms' => $durationMs,
                    'registered_at' => $registration->successful ? $finishedAt : null,
                    'registered_by_user_id' => $registration->successful ? $actor->id : null,
                    'updated_by_user_id' => $actor->id,
                    'registration_claim' => null,
                    'registration_claimed_at' => null,
                    'manual_review_required' => $requiresManualReview,
                ]);

                return $locked->refresh();
            }, 3);

            if ($registration->successful) {
                SignificantEventRegistered::dispatch((int) $event->company_id, (int) $event->id);

                return $this->result($event, $safeMessage, detected: true, registered: true);
            }

            return $this->result(
                $event,
                $safeMessage,
                detected: true,
                pending: ! $requiresManualReview,
                retryable: ! $requiresManualReview,
            );
        } catch (Throwable $exception) {
            $errorType = $this->errorClassifier->classify($exception);
            $safeMessage = $this->sanitizer->text($exception::class.': '.$exception->getMessage(), $request->apiToken)
                ?: 'Error no identificado al registrar el evento.';

            DB::transaction(function () use ($event, $actor, $attempt, $claim, $errorType, $safeMessage): void {
                $attempt->update([
                    'attempt_status' => SiatAttemptStatus::Failed,
                    'failure_category' => $errorType->failureCategory(),
                    'message' => $safeMessage,
                    'finished_at' => $this->attemptFinishedAt($attempt),
                ]);

                SinSignificantEvent::query()
                    ->withoutGlobalScope('company')
                    ->where('company_id', $event->company_id)
                    ->whereKey($event->id)
                    ->where('registration_claim', $claim)
                    ->update([
                        'event_status' => SignificantEventStatus::PendingRegistration,
                        'status_label' => 'Pendiente de reintento',
                        'message' => $safeMessage,
                        'updated_by_user_id' => $actor->id,
                        'registration_claim' => null,
                        'registration_claimed_at' => null,
                        'updated_at' => now(),
                    ]);
            }, 3);

            return $this->result(
                $this->eventForCompany($event),
                $safeMessage,
                detected: true,
                pending: true,
                retryable: true,
            );
        }
    }

    public function correctManually(
        SinSignificantEvent $significantEvent,
        User $administrator,
        string $reason,
        ?int $eventCode = null,
        ?string $description = null,
    ): SinSignificantEvent {
        $event = $this->eventForCompany($significantEvent);
        $administrator = $this->safeActor($event, $administrator)
            ?? throw new DomainException('El administrador no pertenece a la empresa del evento.');

        if ($this->isTerminal($event)) {
            throw new DomainException('Un evento registrado o cerrado no admite correcciones manuales.');
        }

        if (blank($reason) || ($eventCode === null && blank($description))) {
            throw new DomainException('La correccion exige un motivo y al menos un dato corregido.');
        }

        if ($eventCode !== null && ! SinCatalogItem::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $event->company_id)
            ->where('catalog_key', 'eventos_significativos')
            ->where('classifier_code', (string) $eventCode)
            ->active()
            ->exists()) {
            throw new DomainException('El codigo indicado no existe en el catalogo SIAT sincronizado de la empresa.');
        }

        return DB::transaction(function () use ($event, $administrator, $reason, $eventCode, $description): SinSignificantEvent {
            $locked = SinSignificantEvent::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $event->company_id)
                ->lockForUpdate()
                ->findOrFail($event->id);

            $changes = array_filter([
                'event_code' => $eventCode,
                'event_description' => filled($description) ? trim((string) $description) : null,
                'administrative_correction_reason' => trim($reason),
                'administratively_corrected_by_user_id' => $administrator->id,
                'administratively_corrected_at' => now(),
                'updated_by_user_id' => $administrator->id,
                'manual_review_required' => false,
            ], static fn (mixed $value): bool => $value !== null);
            $oldValues = array_intersect_key($locked->getAttributes(), $changes);

            SinSignificantEvent::withoutAuditing(fn () => $locked->update($changes));
            $this->recordAdministrativeAudit($locked, $administrator, $oldValues, $changes);

            return $locked->refresh();
        }, 3);
    }

    public function regularizeForRetry(
        SinSignificantEvent $significantEvent,
        User $administrator,
        string $reason,
    ): SinSignificantEvent {
        $event = $this->eventForCompany($significantEvent);
        $administrator = $this->safeActor($event, $administrator)
            ?? throw new DomainException('El administrador no pertenece a la empresa del evento.');

        if ($this->isTerminal($event) || $event->transaccion) {
            throw new DomainException('Un evento ya registrado o cerrado no admite regularización.');
        }

        if (! in_array($event->event_status, [
            SignificantEventStatus::RecoveryDetected,
            SignificantEventStatus::PendingRegistration,
            SignificantEventStatus::Failed,
        ], true)) {
            throw new DomainException('El evento no se encuentra en un estado regularizable.');
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw new DomainException('La regularización exige un motivo de al menos 10 caracteres.');
        }

        return DB::transaction(function () use ($event, $administrator, $reason): SinSignificantEvent {
            $locked = SinSignificantEvent::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $event->company_id)
                ->lockForUpdate()
                ->findOrFail($event->id);

            $endedAt = $locked->ended_at;
            if ($endedAt === null || $endedAt->lessThanOrEqualTo($locked->started_at)) {
                $endedAt = $locked->started_at->addSecond();
            }

            $changes = [
                'ended_at' => $endedAt,
                'recovery_detected_at' => $endedAt,
                'recovery_sin_cufd_id' => null,
                'event_status' => SignificantEventStatus::PendingRegistration,
                'status_label' => 'Regularización administrativa encolada',
                'message' => 'Datos corregidos; pendiente de un nuevo registro ante el SIAT.',
                'administrative_correction_reason' => trim($reason),
                'administratively_corrected_by_user_id' => $administrator->id,
                'administratively_corrected_at' => now(),
                'updated_by_user_id' => $administrator->id,
                'manual_review_required' => false,
                'registration_claim' => null,
                'registration_claimed_at' => null,
            ];
            $oldValues = array_intersect_key($locked->getAttributes(), $changes);

            SinSignificantEvent::withoutAuditing(fn () => $locked->update($changes));
            $this->recordAdministrativeAudit($locked, $administrator, $oldValues, $changes);

            return $locked->refresh();
        }, 3);
    }

    private function claimRegistration(
        SinSignificantEvent $event,
        User $actor,
        SignificantEventRegistrationRequest $request,
        string $claim,
    ): ?SinSiatAttempt {
        return DB::transaction(function () use ($event, $actor, $request, $claim): ?SinSiatAttempt {
            $locked = SinSignificantEvent::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $event->company_id)
                ->lockForUpdate()
                ->findOrFail($event->id);

            if ($this->isRegistered($locked)) {
                return null;
            }

            if ($locked->registration_claim !== null
                && $locked->registration_claimed_at?->gt(now()->subMinutes(
                    max(1, (int) config('siat.contingency_recovery.registration_claim_ttl_minutes', 5)),
                ))) {
                return null;
            }

            if ($locked->registration_claim !== null) {
                $inFlight = SinSiatAttempt::query()
                    ->withoutGlobalScope('company')
                    ->where('company_id', $locked->company_id)
                    ->where('sin_significant_event_id', $locked->id)
                    ->where('operation', SiatOperation::RegisterSignificantEvent)
                    ->where('attempt_status', SiatAttemptStatus::Sending)
                    ->latest('attempt_number')
                    ->lockForUpdate()
                    ->first();

                if ($inFlight) {
                    $message = 'El worker termino durante el registro; el resultado ante SIAT es incierto.';
                    $inFlight->update([
                        'attempt_status' => SiatAttemptStatus::Uncertain,
                        'message' => $message,
                        'finished_at' => $this->attemptFinishedAt($inFlight),
                    ]);
                    $locked->update([
                        'event_status' => SignificantEventStatus::Failed,
                        'status_label' => 'Conciliacion administrativa requerida',
                        'message' => $message.' No se reintentara automaticamente.',
                        'manual_review_required' => true,
                        'registration_claim' => null,
                        'registration_claimed_at' => null,
                    ]);

                    return null;
                }
            }

            $payload = $this->sanitizer->data($request->payload(), $request->apiToken) ?? [];
            $attemptNumber = (int) SinSiatAttempt::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $event->company_id)
                ->where('sin_significant_event_id', $event->id)
                ->max('attempt_number') + 1;
            $attempt = SinSiatAttempt::query()->create([
                'company_id' => $event->company_id,
                'sin_significant_event_id' => $event->id,
                'user_id' => $actor->id,
                'idempotency_key' => (string) Str::uuid(),
                'operation' => SiatOperation::RegisterSignificantEvent,
                'attempt_number' => $attemptNumber,
                'attempt_status' => SiatAttemptStatus::Sending,
                'endpoint' => $request->operationsWsdl,
                'request_hash' => hash('sha256', json_encode($request->payload(), JSON_THROW_ON_ERROR)),
                'request_payload' => $payload,
                'started_at' => now(),
            ]);

            $locked->update([
                'event_status' => SignificantEventStatus::PendingRegistration,
                'status_label' => 'Registrando evento',
                'request_payload' => $payload,
                'updated_by_user_id' => $actor->id,
                'registration_claim' => $claim,
                'registration_claimed_at' => now(),
            ]);

            return $attempt;
        }, 3);
    }

    /** @param array<int, array<string, mixed>> $messages */
    private function storeMessages(
        SinSiatAttempt $attempt,
        array $messages,
        string $fallback,
        bool $successful,
    ): void {
        if ($messages === []) {
            $messages = [['descripcion' => $fallback]];
        }

        foreach ($messages as $position => $row) {
            $description = trim((string) ($row['descripcion'] ?? $row['mensaje'] ?? $row['descripcionMensaje'] ?? ''));

            if ($description === '') {
                continue;
            }

            $code = trim((string) ($row['codigo'] ?? $row['codigoMensaje'] ?? ''));
            SinResponseMessage::query()->firstOrCreate([
                'company_id' => $attempt->company_id,
                'sin_siat_attempt_id' => $attempt->id,
                'message_key' => hash('sha256', $position.'|'.$code.'|'.$description),
            ], [
                'service' => 'registroEventoSignificativo',
                'message_code' => $code !== '' ? $code : null,
                'severity' => $successful ? SiatMessageSeverity::Info : SiatMessageSeverity::Error,
                'description' => $description,
                'raw_data' => $this->sanitizer->data($row),
                'received_at' => now(),
            ]);
        }
    }

    private function pending(
        SinSignificantEvent $event,
        ?User $actor,
        string $message,
        bool $retryable,
    ): ContingencyRecoveryResult {
        $event->update([
            'event_status' => SignificantEventStatus::PendingRegistration,
            'status_label' => 'Pendiente',
            'message' => $message,
            'updated_by_user_id' => $actor?->id,
        ]);

        return $this->result($event->refresh(), $message, detected: true, pending: true, retryable: $retryable);
    }

    private function markForReview(
        SinSignificantEvent $event,
        ?User $actor,
        string $message,
        bool $retryable = false,
    ): ContingencyRecoveryResult {
        $event->update([
            'manual_review_required' => true,
            'message' => $message,
            'updated_by_user_id' => $actor?->id,
        ]);

        return $this->result(
            $event->refresh(),
            $message,
            detected: $event->recovery_detected_at !== null,
            pending: true,
            retryable: $retryable,
        );
    }

    private function eventForCompany(SinSignificantEvent $event): SinSignificantEvent
    {
        return SinSignificantEvent::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $event->company_id)
            ->findOrFail($event->id);
    }

    private function safeActor(SinSignificantEvent $event, ?User $actor): ?User
    {
        if ($actor === null) {
            return null;
        }

        return (int) $actor->company_id === (int) $event->company_id ? $actor : null;
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function attemptFinishedAt(SinSiatAttempt $attempt): CarbonImmutable
    {
        $finishedAt = now()->toImmutable();

        return $attempt->started_at && $finishedAt->lessThan($attempt->started_at)
            ? $attempt->started_at
            : $finishedAt;
    }

    private function recordAdministrativeAudit(
        SinSignificantEvent $event,
        User $administrator,
        array $oldValues,
        array $newValues,
    ): void {
        Audit::query()->create([
            'company_id' => $event->company_id,
            'user_type' => $administrator->getMorphClass(),
            'user_id' => $administrator->getKey(),
            'event' => 'updated',
            'auditable_type' => $event->getMorphClass(),
            'auditable_id' => $event->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'url' => app()->runningInConsole() ? 'console' : request()->fullUrl(),
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => app()->runningInConsole() ? null : request()->userAgent(),
            'tags' => 'company:'.$event->company_id.',SinSignificantEvent,manual-correction',
        ]);
    }

    private function isRegistered(SinSignificantEvent $event): bool
    {
        return $event->event_status === SignificantEventStatus::Registered
            || ($event->transaccion === true && filled($event->reception_code));
    }

    private function isTerminal(SinSignificantEvent $event): bool
    {
        return $this->isRegistered($event)
            || $event->closed_at !== null
            || in_array($event->event_status, [
                SignificantEventStatus::Packaging,
                SignificantEventStatus::Sending,
                SignificantEventStatus::Validating,
                SignificantEventStatus::Completed,
                SignificantEventStatus::Expired,
            ], true);
    }

    private function result(
        SinSignificantEvent $event,
        string $message,
        bool $detected = false,
        bool $registered = false,
        bool $pending = false,
        bool $retryable = false,
    ): ContingencyRecoveryResult {
        return new ContingencyRecoveryResult(
            event: $event,
            recoveryDetected: $detected,
            registered: $registered,
            pending: $pending,
            retryable: $retryable,
            message: $message,
        );
    }
}
