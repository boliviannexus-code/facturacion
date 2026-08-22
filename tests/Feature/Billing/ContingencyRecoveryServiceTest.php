<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\InvoiceFiscalStatus;
use App\Enums\SiatAttemptStatus;
use App\Enums\SiatErrorType;
use App\Enums\SiatOperation;
use App\Enums\SignificantEventStatus;
use App\Exceptions\ContingencyRecoveryPendingException;
use App\Jobs\BuildContingencyPackagesJob;
use App\Jobs\RegisterSignificantEventJob;
use App\Models\Company;
use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinBranch;
use App\Models\SinCatalogItem;
use App\Models\SinCufd;
use App\Models\SinCuis;
use App\Models\SinInvoiceIssue;
use App\Models\SinPointOfSale;
use App\Models\SinSiatAttempt;
use App\Models\SinSignificantEvent;
use App\Models\User;
use App\Services\Siat\ContingencyRecoveryService;
use App\Services\Siat\Recovery\Contracts\RecoveryCufdProvider;
use App\Services\Siat\Recovery\Contracts\SignificantEventRegistrar;
use App\Services\Siat\Recovery\CufdAcquisitionResult;
use App\Services\Siat\Recovery\SignificantEventRegistrationResult;
use App\Services\Siat\SiatCommunicationService;
use App\Services\Siat\SiatHealthCheckResult;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Fakes\SequenceRecoveryCufdProvider;
use Tests\Fakes\SequenceSignificantEventRegistrar;
use Tests\TestCase;

final class ContingencyRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_successful_recovery_preserves_start_registers_event_and_starts_packaging(): void
    {
        $context = $this->context();
        $this->availableHealthCheck();
        [$cufdProvider, $registrar] = $this->simulateRegistration($context, [$this->accepted('EVENT-REC-1')]);
        $service = app(ContingencyRecoveryService::class);
        $originalStart = $context['event']->started_at;

        $detected = $service->detectRecovery($context['event'], $context['user']);

        self::assertTrue($detected->recoveryDetected);
        self::assertSame(SignificantEventStatus::RecoveryDetected, $detected->event->event_status);
        self::assertSame(
            $originalStart->format('Y-m-d H:i:s'),
            $detected->event->started_at->format('Y-m-d H:i:s'),
        );
        self::assertTrue($detected->event->ended_at?->equalTo($detected->event->recovery_detected_at));
        Queue::assertPushed(RegisterSignificantEventJob::class);

        $registered = $service->registerRecoveredEvent($detected->event, $context['user']);

        self::assertTrue($registered->registered);
        self::assertSame(SignificantEventStatus::Registered, $registered->event->event_status);
        self::assertSame('EVENT-REC-1', $registered->event->reception_code);
        self::assertNull($registered->event->closed_at);
        self::assertSame(1, $cufdProvider->calls);
        self::assertSame(1, $registrar->calls);
        self::assertSame($context['oldCufd']->cufd_code, $registrar->requests[0]->eventCufd);
        self::assertNotSame($context['oldCufd']->cufd_code, $registrar->requests[0]->currentCufd);
        $this->assertDatabaseHas('sin_siat_attempts', [
            'sin_significant_event_id' => $context['event']->id,
            'attempt_status' => 'SUCCEEDED',
            'reception_code' => 'EVENT-REC-1',
        ]);
        $this->assertDatabaseHas('sin_response_messages', ['description' => 'Evento registrado.']);
        Queue::assertPushed(BuildContingencyPackagesJob::class, fn ($job): bool => $job->significantEventId === $context['event']->id);
    }

    public function test_recovery_detected_in_the_starting_second_uses_a_minimum_one_second_duration(): void
    {
        $context = $this->context();
        $this->travelTo($context['event']->started_at);
        $this->availableHealthCheck();

        $detected = app(ContingencyRecoveryService::class)
            ->detectRecovery($context['event'], $context['user']);

        self::assertTrue($detected->recoveryDetected);
        self::assertTrue(
            $detected->event->ended_at?->equalTo($context['event']->started_at->addSecond()),
        );
        self::assertTrue(
            $detected->event->recovery_detected_at?->equalTo($context['event']->started_at->addSecond()),
        );
        self::assertSame(1.0, $detected->event->started_at->diffInSeconds($detected->event->ended_at));

        $this->travelBack();
    }

    public function test_operator_selects_official_event_before_recovery_registration(): void
    {
        $context = $this->context();
        $context['event']->update(['requires_manual_processing' => true]);
        SinCatalogItem::factory()->create([
            'company_id' => $context['company']->id,
            'catalog_key' => 'eventos_significativos',
            'classifier_code' => '7',
            'description' => 'Evento oficial seleccionado.',
            'is_active' => true,
        ]);
        $this->availableHealthCheck();
        [, $registrar] = $this->simulateRegistration($context, [$this->accepted('EVENT-MANUAL-7')]);

        $result = app(ContingencyRecoveryService::class)->prepareAndDetectRecovery(
            $context['event'],
            $context['user'],
            7,
            'Descripción real indicada por el operador.',
        );

        self::assertTrue($result->registered);
        self::assertSame(7, $result->event->event_code);
        self::assertSame('Descripción real indicada por el operador.', $result->event->event_description);
        self::assertSame(SignificantEventStatus::Registered, $result->event->event_status);
        self::assertSame('EVENT-MANUAL-7', $result->event->reception_code);
        self::assertFalse($result->event->requires_manual_processing);
        self::assertSame(1, $registrar->calls);
        Queue::assertNotPushed(RegisterSignificantEventJob::class);
        Queue::assertPushed(BuildContingencyPackagesJob::class);
    }

    public function test_cufd_failure_keeps_event_pending_without_calling_registrar(): void
    {
        $context = $this->recoveredContext();
        $provider = new SequenceRecoveryCufdProvider([
            new CufdAcquisitionResult(false, null, 'CUFD temporalmente no disponible.'),
        ]);
        $registrar = new SequenceSignificantEventRegistrar([]);
        $this->bindFakes($provider, $registrar);

        $result = app(ContingencyRecoveryService::class)
            ->registerRecoveredEvent($context['event'], $context['user']);

        self::assertTrue($result->pending);
        self::assertTrue($result->retryable);
        self::assertSame(SignificantEventStatus::PendingRegistration, $result->event->event_status);
        self::assertNull($result->event->recovery_sin_cufd_id);
        self::assertSame(0, $registrar->calls);
    }

    public function test_registration_failure_is_saved_and_remains_retryable(): void
    {
        $context = $this->recoveredContext();
        [, $registrar] = $this->simulateRegistration($context, [$this->rejected('Registro rechazado temporalmente.')]);

        $result = app(ContingencyRecoveryService::class)
            ->registerRecoveredEvent($context['event'], $context['user']);

        self::assertTrue($result->pending);
        self::assertTrue($result->retryable);
        self::assertSame(SignificantEventStatus::PendingRegistration, $result->event->event_status);
        self::assertSame(1, $registrar->calls);
        $this->assertDatabaseHas('sin_siat_attempts', [
            'sin_significant_event_id' => $context['event']->id,
            'attempt_status' => 'FAILED',
        ]);
        $this->assertDatabaseHas('sin_response_messages', ['description' => 'Registro rechazado temporalmente.']);
    }

    public function test_invalid_event_date_range_remains_available_for_retry(): void
    {
        $context = $this->recoveredContext();
        $result981 = new SignificantEventRegistrationResult(
            successful: false,
            receptionCode: null,
            message: 'RANGO DE FECHAS DE EVENTO SIGNIFICATIVO INVALIDO',
            response: ['RespuestaListaEventos' => ['transaccion' => false]],
            messages: [[
                'codigo' => 981,
                'descripcion' => 'RANGO DE FECHAS DE EVENTO SIGNIFICATIVO INVALIDO',
            ]],
            durationMs: 20,
        );
        $this->simulateRegistration($context, [$result981]);

        $result = app(ContingencyRecoveryService::class)
            ->registerRecoveredEvent($context['event'], $context['user']);

        self::assertTrue($result->pending);
        self::assertTrue($result->retryable);
        self::assertFalse($result->event->manual_review_required);
        self::assertSame(SignificantEventStatus::PendingRegistration, $result->event->event_status);
    }

    public function test_failed_registration_can_be_retried_successfully(): void
    {
        $context = $this->recoveredContext();
        [, $registrar] = $this->simulateRegistration($context, [
            $this->rejected('Primer intento fallido.'),
            $this->accepted('EVENT-RETRY-OK'),
        ]);
        $service = app(ContingencyRecoveryService::class);
        $job = new RegisterSignificantEventJob(
            $context['company']->id,
            $context['event']->id,
            $context['user']->id,
        );

        try {
            $job->handle($service);
            self::fail('El primer intento debio solicitar un reintento del job.');
        } catch (ContingencyRecoveryPendingException) {
            // Laravel volvera a ejecutar el mismo job usando el backoff configurado.
        }

        $job->handle($service);
        $event = $context['event']->refresh();

        self::assertSame(SignificantEventStatus::Registered, $event->event_status);
        self::assertSame('EVENT-RETRY-OK', $event->reception_code);
        self::assertSame(2, $registrar->calls);
        $this->assertDatabaseCount('sin_siat_attempts', 2);
    }

    public function test_repeated_job_does_not_register_an_already_registered_event_twice(): void
    {
        $context = $this->recoveredContext();
        [$provider, $registrar] = $this->simulateRegistration($context, [$this->accepted('EVENT-ONCE')]);
        $job = new RegisterSignificantEventJob(
            $context['company']->id,
            $context['event']->id,
            $context['user']->id,
        );
        $service = app(ContingencyRecoveryService::class);

        $job->handle($service);
        $job->handle($service);

        self::assertSame(1, $provider->calls);
        self::assertSame(1, $registrar->calls);
        $this->assertDatabaseCount('sin_siat_attempts', 1);
    }

    public function test_stale_registration_claim_requires_review_before_an_explicit_retry(): void
    {
        $context = $this->recoveredContext();
        [, $registrar] = $this->simulateRegistration($context, [$this->accepted('EVENT-AFTER-REVIEW')]);
        $context['event']->update([
            'event_status' => SignificantEventStatus::PendingRegistration,
            'registration_claim' => fake()->uuid(),
            'registration_claimed_at' => now()->subMinutes(10),
        ]);
        $attempt = SinSiatAttempt::factory()->create([
            'company_id' => $context['company']->id,
            'sin_invoice_issue_id' => null,
            'sin_invoice_package_id' => null,
            'sin_significant_event_id' => $context['event']->id,
            'operation' => SiatOperation::RegisterSignificantEvent,
            'attempt_number' => 1,
            'attempt_status' => SiatAttemptStatus::Sending,
            'finished_at' => null,
        ]);
        $service = app(ContingencyRecoveryService::class);

        $uncertain = $service->registerRecoveredEvent($context['event']->refresh(), $context['user']);

        self::assertSame(SignificantEventStatus::Failed, $uncertain->event->event_status);
        self::assertTrue($uncertain->event->manual_review_required);
        self::assertSame(SiatAttemptStatus::Uncertain, $attempt->refresh()->attempt_status);
        self::assertSame(0, $registrar->calls);

        $retried = $service->registerRecoveredEvent($uncertain->event->refresh(), $context['user']);

        self::assertSame(SignificantEventStatus::Registered, $retried->event->event_status);
        self::assertSame('EVENT-AFTER-REVIEW', $retried->event->reception_code);
        self::assertSame(1, $registrar->calls);
    }

    public function test_company_filter_does_not_process_an_event_from_another_company(): void
    {
        $first = $this->context();
        $second = $this->context();
        $this->availableHealthCheck(times: 1);

        $this->artisan('siat:recover-open-contingencies', ['--company' => $first['company']->id])
            ->assertSuccessful();

        self::assertSame(SignificantEventStatus::RecoveryDetected, $first['event']->refresh()->event_status);
        self::assertSame(SignificantEventStatus::Open, $second['event']->refresh()->event_status);
        Queue::assertPushed(RegisterSignificantEventJob::class, 1);

        $this->expectException(ModelNotFoundException::class);
        (new RegisterSignificantEventJob(
            $first['company']->id,
            $second['event']->id,
            $first['user']->id,
        ))->handle(app(ContingencyRecoveryService::class));
    }

    public function test_failed_event_can_be_rehabilitated_explicitly_and_requeued(): void
    {
        $context = $this->context();
        $context['event']->forceFill([
            'event_status' => SignificantEventStatus::Failed,
            'manual_review_required' => true,
            'ended_at' => now(),
            'recovery_detected_at' => now(),
        ])->save();
        SinCatalogItem::factory()->create([
            'company_id' => $context['company']->id,
            'catalog_key' => 'eventos_significativos',
            'classifier_code' => '2',
            'description' => 'Inaccesibilidad al servicio web del SIN.',
            'is_active' => true,
        ]);
        $this->availableHealthCheck(times: 1);

        $this->artisan('siat:recover-open-contingencies', [
            '--event' => $context['event']->id,
            '--actor' => $context['user']->id,
            '--event-code' => 2,
            '--reason' => 'Reenvío autorizado después de corregir la zona horaria.',
        ])->assertSuccessful();

        self::assertFalse($context['event']->refresh()->manual_review_required);
        Queue::assertPushed(RegisterSignificantEventJob::class, 1);
    }

    public function test_closed_event_is_ignored_without_checking_communication(): void
    {
        $context = $this->context();
        $context['event']->update([
            'event_status' => SignificantEventStatus::Completed,
            'closed_at' => now(),
        ]);
        $this->mock(SiatCommunicationService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('verify');
        });

        $result = app(ContingencyRecoveryService::class)
            ->detectRecovery($context['event'], $context['user']);

        self::assertFalse($result->recoveryDetected);
        self::assertSame(SignificantEventStatus::Completed, $result->event->event_status);
        Queue::assertNothingPushed();
    }

    public function test_manual_correction_is_attributed_and_does_not_change_original_dates(): void
    {
        $context = $this->context();
        $start = $context['event']->started_at;
        SinCatalogItem::factory()->create([
            'company_id' => $context['company']->id,
            'catalog_key' => 'eventos_significativos',
            'classifier_code' => '2',
            'description' => 'Evento autorizado por SIAT.',
            'is_active' => true,
        ]);

        $event = app(ContingencyRecoveryService::class)->correctManually(
            $context['event'],
            $context['user'],
            'Correccion autorizada por administracion.',
            eventCode: 2,
            description: 'Descripcion corregida administrativamente.',
        );

        self::assertSame(2, $event->event_code);
        self::assertSame($context['user']->id, $event->administratively_corrected_by_user_id);
        self::assertSame(
            $start->format('Y-m-d H:i:s'),
            $event->started_at->format('Y-m-d H:i:s'),
        );
        self::assertNotNull($event->administratively_corrected_at);
        $this->assertDatabaseHas('audits', [
            'auditable_type' => SinSignificantEvent::class,
            'auditable_id' => $event->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $branch = SinBranch::factory()->create(['company_id' => $company->id, 'branch_code' => 1]);
        $point = SinPointOfSale::factory()->create([
            'company_id' => $company->id,
            'sin_branch_id' => $branch->id,
            'point_of_sale_code' => 1,
        ]);
        $token = SinApiToken::factory()->create(['company_id' => $company->id]);
        $authorization = SinAuthorization::factory()->create([
            'company_id' => $company->id,
            'tax_id' => '123456789',
        ]);
        $cuis = SinCuis::factory()->create([
            'company_id' => $company->id,
            'sin_api_token_id' => $token->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $branch->id,
            'sin_point_of_sale_id' => $point->id,
            'branch_code' => 1,
            'point_of_sale_code' => 1,
        ]);
        $oldCufd = SinCufd::factory()->create([
            'company_id' => $company->id,
            'sin_api_token_id' => $token->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $branch->id,
            'sin_point_of_sale_id' => $point->id,
            'sin_cuis_id' => $cuis->id,
            'branch_code' => 1,
            'point_of_sale_code' => 1,
            'expires_at' => now()->subMinute(),
        ]);
        $event = SinSignificantEvent::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'sin_api_token_id' => $token->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $branch->id,
            'sin_point_of_sale_id' => $point->id,
            'sin_cuis_id' => $cuis->id,
            'sin_cufd_id' => $oldCufd->id,
            'started_at' => now()->subHours(2)->startOfSecond(),
            'event_status' => SignificantEventStatus::Open,
            'ended_at' => null,
            'recovery_detected_at' => null,
        ]);
        $invoice = SinInvoiceIssue::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'sin_api_token_id' => $token->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $branch->id,
            'sin_point_of_sale_id' => $point->id,
            'sin_cuis_id' => $cuis->id,
            'sin_cufd_id' => $oldCufd->id,
            'sin_significant_event_id' => $event->id,
            'fiscal_status' => InvoiceFiscalStatus::OfflineIssued,
        ]);

        return compact(
            'company', 'user', 'branch', 'point', 'token', 'authorization',
            'cuis', 'oldCufd', 'event', 'invoice',
        );
    }

    /** @return array<string, mixed> */
    private function recoveredContext(): array
    {
        $context = $this->context();
        $recoveredAt = now()->startOfSecond();
        $context['event']->update([
            'event_status' => SignificantEventStatus::RecoveryDetected,
            'ended_at' => $recoveredAt,
            'recovery_detected_at' => $recoveredAt,
        ]);

        return $context;
    }

    /**
     * @param  array<int, SignificantEventRegistrationResult|\Throwable>  $registrations
     * @return array{SequenceRecoveryCufdProvider, SequenceSignificantEventRegistrar}
     */
    private function simulateRegistration(array $context, array $registrations): array
    {
        $newCufd = SinCufd::factory()->create([
            'company_id' => $context['company']->id,
            'sin_api_token_id' => $context['token']->id,
            'sin_authorization_id' => $context['authorization']->id,
            'sin_branch_id' => $context['branch']->id,
            'sin_point_of_sale_id' => $context['point']->id,
            'sin_cuis_id' => $context['cuis']->id,
            'branch_code' => 1,
            'point_of_sale_code' => 1,
            'cufd_code' => 'NEW-CUFD-'.fake()->unique()->numerify('########'),
            'expires_at' => now()->addDay(),
        ]);
        $provider = new SequenceRecoveryCufdProvider([
            new CufdAcquisitionResult(true, $newCufd, 'CUFD recuperado.'),
        ]);
        $registrar = new SequenceSignificantEventRegistrar($registrations);
        $this->bindFakes($provider, $registrar);

        return [$provider, $registrar];
    }

    private function bindFakes(
        SequenceRecoveryCufdProvider $provider,
        SequenceSignificantEventRegistrar $registrar,
    ): void {
        $this->app->instance(RecoveryCufdProvider::class, $provider);
        $this->app->instance(SignificantEventRegistrar::class, $registrar);
    }

    private function availableHealthCheck(int $times = 1): void
    {
        $result = new SiatHealthCheckResult(
            available: true,
            errorType: SiatErrorType::Available,
            userMessage: 'SIAT disponible.',
            technicalMessage: 'Respuesta simulada.',
            operation: 'verificarComunicacion',
            wsdlUrl: 'https://siat.test/operations?wsdl',
            durationMs: 1,
            requestDurationMs: 1,
            attempts: 1,
            shouldOpenContingency: false,
            checkedAt: now()->format('d/m/Y H:i:s'),
        );
        $this->mock(SiatCommunicationService::class, function (MockInterface $mock) use ($result, $times): void {
            $mock->shouldReceive('verify')->times($times)->andReturn($result);
        });
    }

    private function accepted(string $receptionCode): SignificantEventRegistrationResult
    {
        return new SignificantEventRegistrationResult(
            successful: true,
            receptionCode: $receptionCode,
            message: 'Evento registrado.',
            response: [
                'RespuestaListaEventos' => [
                    'transaccion' => true,
                    'codigoRecepcionEventoSignificativo' => $receptionCode,
                    'mensajesList' => [['codigo' => 'SIM-OK', 'descripcion' => 'Evento registrado.']],
                ],
            ],
            messages: [['codigo' => 'SIM-OK', 'descripcion' => 'Evento registrado.']],
            durationMs: 15,
        );
    }

    private function rejected(string $message): SignificantEventRegistrationResult
    {
        return new SignificantEventRegistrationResult(
            successful: false,
            receptionCode: null,
            message: $message,
            response: [
                'RespuestaListaEventos' => [
                    'transaccion' => false,
                    'mensajesList' => [['codigo' => 'SIM-ERROR', 'descripcion' => $message]],
                ],
            ],
            messages: [['codigo' => 'SIM-ERROR', 'descripcion' => $message]],
            durationMs: 20,
        );
    }
}
