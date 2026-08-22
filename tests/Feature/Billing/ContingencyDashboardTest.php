<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\InvoiceCommercialStatus;
use App\Enums\InvoiceEmissionMode;
use App\Enums\InvoiceFiscalStatus;
use App\Enums\InvoicePackageStatus;
use App\Enums\SiatErrorType;
use App\Enums\SignificantEventStatus;
use App\Jobs\BuildContingencyPackagesJob;
use App\Jobs\CheckPackageValidationJob;
use App\Jobs\RegisterSignificantEventJob;
use App\Jobs\SendContingencyPackageJob;
use App\Models\Company;
use App\Models\Customer;
use App\Models\SinBranch;
use App\Models\SinCafcRange;
use App\Models\SinCatalogItem;
use App\Models\SinCommunicationLog;
use App\Models\SinInvoiceIssue;
use App\Models\SinInvoicePackage;
use App\Models\SinManualContingencyInvoice;
use App\Models\SinMonitoringAlert;
use App\Models\SinPointOfSale;
use App\Models\SinSignificantEvent;
use App\Models\User;
use App\Services\Siat\SiatCommunicationService;
use App\Services\Siat\SiatDateTime;
use App\Services\Siat\SiatHealthCheckResult;
use App\Services\Siat\SignificantEventService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ContingencyDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private SinBranch $branch;

    private SinPointOfSale $point;

    private SinSignificantEvent $event;

    private SinInvoiceIssue $offlineInvoice;

    private SinInvoicePackage $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['name' => 'Empresa Monitor SIAT']);
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        $this->branch = SinBranch::factory()->create(['company_id' => $this->company->id, 'name' => 'Sucursal Operativa']);
        $this->point = SinPointOfSale::factory()->create(['company_id' => $this->company->id, 'sin_branch_id' => $this->branch->id, 'name' => 'Caja contingencia']);
        $this->event = SinSignificantEvent::factory()->create([
            'company_id' => $this->company->id, 'user_id' => $this->user->id,
            'sin_branch_id' => $this->branch->id, 'sin_point_of_sale_id' => $this->point->id,
            'event_description' => 'Corte de Internet monitoreado', 'expires_at' => now()->addHours(24),
        ]);
        SinCommunicationLog::factory()->create([
            'company_id' => $this->company->id, 'user_id' => $this->user->id,
            'sin_branch_id' => $this->branch->id, 'sin_point_of_sale_id' => $this->point->id,
        ]);
        $customer = Customer::factory()->create(['company_id' => $this->company->id, 'name' => 'Cliente Offline Visible']);
        $this->offlineInvoice = SinInvoiceIssue::factory()->create([
            'company_id' => $this->company->id, 'user_id' => $this->user->id,
            'customer_id' => $customer->id, 'sin_branch_id' => $this->branch->id,
            'sin_point_of_sale_id' => $this->point->id, 'sin_cuis_id' => $this->event->sin_cuis_id,
            'sin_cufd_id' => $this->event->sin_cufd_id, 'sin_significant_event_id' => $this->event->id,
            'emission_mode' => InvoiceEmissionMode::OfflineDigital,
            'commercial_status' => InvoiceCommercialStatus::Paid,
            'fiscal_status' => InvoiceFiscalStatus::OfflineIssued,
            'cuf' => 'CUF-MONITOR-OFFLINE-001', 'xml_path' => 'invoices/test/monitor.xml',
        ]);
        $this->package = SinInvoicePackage::factory()->create([
            'company_id' => $this->company->id, 'sin_branch_id' => $this->branch->id,
            'sin_point_of_sale_id' => $this->point->id, 'sin_cuis_id' => $this->event->sin_cuis_id,
            'sin_cufd_id' => $this->event->sin_cufd_id, 'sin_significant_event_id' => $this->event->id,
            'package_status' => InvoicePackageStatus::PendingSend, 'invoice_count' => 1,
        ]);
        $range = SinCafcRange::factory()->create([
            'company_id' => $this->company->id, 'sin_branch_id' => $this->branch->id,
            'sin_point_of_sale_id' => $this->point->id, 'created_by_user_id' => $this->user->id,
        ]);
        SinManualContingencyInvoice::factory()->create([
            'sin_cafc_range_id' => $range->id, 'company_id' => $this->company->id,
            'sin_branch_id' => $this->branch->id, 'sin_point_of_sale_id' => $this->point->id,
            'sin_significant_event_id' => $this->event->id, 'manual_invoice_number' => 10,
        ]);
    }

    public function test_dashboard_displays_operational_context_metrics_and_separate_statuses(): void
    {
        $this->grant($this->user, 'contingencies.view');

        $this->actingAs($this->user)->get($this->dashboardUrl())
            ->assertOk()
            ->assertSee('Empresa Monitor SIAT')
            ->assertSee('Corte de Internet monitoreado')
            ->assertSee('Cliente Offline Visible')
            ->assertSee('Pagada')
            ->assertSee('Emitida fuera de línea')
            ->assertSee('Manuales por transcribir')
            ->assertSee('Rangos CAFC disponibles')
            ->assertSee(
                'data-start="'.SiatDateTime::localIso($this->event->started_at).'"',
                escape: false,
            );
    }

    public function test_open_event_exposes_manual_significant_event_registration_action(): void
    {
        $this->grant($this->user, 'contingencies.view', 'contingencies.events.retry');

        $this->actingAs($this->user)
            ->get($this->dashboardUrl())
            ->assertOk()
            ->assertSee('Registrar evento significativo')
            ->assertSee(route('billing.contingencies.events.register', $this->event));
    }

    public function test_unaccepted_pending_event_still_requires_official_event_selection(): void
    {
        $this->event->forceFill([
            'event_status' => SignificantEventStatus::PendingRegistration,
            'transaccion' => false,
            'registration_claim' => null,
        ])->save();
        $this->grant($this->user, 'contingencies.view', 'contingencies.events.retry');

        $this->actingAs($this->user)
            ->get($this->dashboardUrl())
            ->assertOk()
            ->assertSee('Registrar evento significativo')
            ->assertSee(route('billing.contingencies.events.register', $this->event));
    }

    public function test_registering_an_event_marked_for_manual_review_returns_warning_instead_of_409(): void
    {
        $this->grant($this->user, 'contingencies.events.retry');
        SinCatalogItem::factory()->create([
            'company_id' => $this->company->id,
            'catalog_key' => 'eventos_significativos',
            'classifier_code' => '1',
            'is_active' => true,
        ]);
        $this->event->forceFill([
            'event_status' => SignificantEventStatus::Failed,
            'manual_review_required' => true,
            'message' => 'EL EVENTO SIGNIFICATIVO NO CORRESPONDE AL CUFD DEL EVENTO REGISTRADO',
        ])->save();

        $this->actingAs($this->user)
            ->post(route('billing.contingencies.events.register', $this->event), [
                'event_code' => 1,
                'description' => 'Interrupción de comunicación con el SIN.',
            ])
            ->assertRedirect()
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'requiere revisión manual'));
    }

    public function test_authorized_user_can_regularize_a_zero_duration_event_and_requeue_it(): void
    {
        Queue::fake();
        $this->grant($this->user, 'contingencies.events.view', 'contingencies.events.retry');
        $startedAt = $this->event->started_at;
        $this->event->forceFill([
            'event_status' => SignificantEventStatus::Failed,
            'ended_at' => $startedAt,
            'recovery_detected_at' => $startedAt,
            'manual_review_required' => true,
            'message' => 'RANGO DE FECHAS DE EVENTO SIGNIFICATIVO INVALIDO',
        ])->save();

        $this->actingAs($this->user)
            ->get(route('billing.contingencies.events.show', $this->event))
            ->assertOk()
            ->assertSee('Regularización administrativa')
            ->assertSee(route('billing.contingencies.events.regularize', $this->event));

        $this->actingAs($this->user)
            ->post(route('billing.contingencies.events.regularize', $this->event), [
                'reason' => 'Corrección autorizada del intervalo fiscal inválido.',
                'confirmation' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $event = $this->event->refresh();
        self::assertSame(SignificantEventStatus::PendingRegistration, $event->event_status);
        self::assertTrue($event->ended_at?->equalTo($startedAt->addSecond()));
        self::assertFalse($event->manual_review_required);
        self::assertNull($event->recovery_sin_cufd_id);
        self::assertSame($this->user->id, $event->administratively_corrected_by_user_id);
        Queue::assertPushed(RegisterSignificantEventJob::class, fn (RegisterSignificantEventJob $job): bool => $job->significantEventId === $event->id);
    }

    public function test_regularization_requires_explicit_confirmation(): void
    {
        Queue::fake();
        $this->grant($this->user, 'contingencies.events.retry');
        $this->event->forceFill(['event_status' => SignificantEventStatus::Failed])->save();

        $this->actingAs($this->user)
            ->post(route('billing.contingencies.events.regularize', $this->event), [
                'reason' => 'Corrección autorizada del evento fiscal.',
            ])
            ->assertSessionHasErrors('confirmation');

        Queue::assertNothingPushed();
    }

    public function test_dashboard_prioritizes_registered_event_over_a_newer_failed_event_for_package_processing(): void
    {
        $this->event->forceFill([
            'event_status' => SignificantEventStatus::Registered,
            'transaccion' => true,
            'reception_code' => 'EVENTO-REGISTRADO-123',
            'status_label' => 'Registrado en el SIN',
            'message' => 'Evento significativo registrado en el SIN.',
        ])->save();
        SinSignificantEvent::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'sin_branch_id' => $this->branch->id,
            'sin_point_of_sale_id' => $this->point->id,
            'event_status' => SignificantEventStatus::Failed,
            'transaccion' => false,
            'started_at' => now()->addMinute(),
            'event_description' => 'Intento fallido más reciente',
        ]);
        $this->grant($this->user, 'contingencies.view', 'contingencies.events.retry', 'contingencies.packages.build');

        $this->actingAs($this->user)->get($this->dashboardUrl())
            ->assertOk()
            ->assertSee('Evento confirmado por SIAT')
            ->assertSee('Generar paquetes')
            ->assertDontSee('Registrar evento significativo');
    }

    public function test_dashboard_prioritizes_the_event_with_the_offline_invoice_over_a_registered_empty_event(): void
    {
        SinSignificantEvent::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'sin_branch_id' => $this->branch->id,
            'sin_point_of_sale_id' => $this->point->id,
            'event_status' => SignificantEventStatus::Registered,
            'transaccion' => true,
            'reception_code' => 'EVENTO-SIN-FACTURAS',
            'started_at' => now()->addMinute(),
            'event_description' => 'Evento registrado sin facturas pendientes',
        ]);
        $this->grant(
            $this->user,
            'contingencies.view',
            'contingencies.events.retry',
            'contingencies.packages.build',
        );

        $this->actingAs($this->user)->get($this->dashboardUrl())
            ->assertOk()
            ->assertSee('Corte de Internet monitoreado')
            ->assertSee('Registrar evento significativo')
            ->assertDontSee('Generar paquetes');
    }

    public function test_independent_registration_is_blocked_when_the_point_of_sale_has_an_offline_event_pending(): void
    {
        $eventsBefore = SinSignificantEvent::query()->count();

        try {
            app(SignificantEventService::class)->registerForPointOfSale($this->user, $this->point, [
                'event_code' => 1,
                'description' => 'No debe crear un evento duplicado.',
                'started_at' => now()->subMinutes(10)->toDateTimeString(),
                'ended_at' => now()->subMinute()->toDateTimeString(),
            ]);
            self::fail('Se esperaba bloquear el evento independiente duplicado.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString(
                "evento #{$this->event->id}",
                mb_strtolower($exception->errors()['sin_point_of_sale_id'][0]),
            );
        }

        self::assertSame($eventsBefore, SinSignificantEvent::query()->count());
    }

    public function test_dashboard_displays_company_wide_alerts_with_a_location_selected(): void
    {
        $this->grant($this->user, 'contingencies.view');
        SinMonitoringAlert::factory()->create([
            'company_id' => $this->company->id,
            'sin_branch_id' => null,
            'sin_point_of_sale_id' => null,
            'title' => 'Certificado próximo a vencer',
        ]);

        $this->actingAs($this->user)->get($this->dashboardUrl())
            ->assertOk()
            ->assertSee('Alertas operativas activas')
            ->assertSee('Certificado próximo a vencer');
    }

    public function test_filters_by_fiscal_status_cuf_number_client_and_event(): void
    {
        $this->grant($this->user, 'contingencies.view');
        $rejectedCustomer = Customer::factory()->create(['company_id' => $this->company->id, 'name' => 'Cliente Rechazado Único']);
        $rejected = SinInvoiceIssue::factory()->create([
            'company_id' => $this->company->id, 'user_id' => $this->user->id,
            'customer_id' => $rejectedCustomer->id, 'sin_branch_id' => $this->branch->id,
            'sin_point_of_sale_id' => $this->point->id, 'sin_cuis_id' => $this->event->sin_cuis_id,
            'sin_cufd_id' => $this->event->sin_cufd_id, 'sin_significant_event_id' => $this->event->id,
            'fiscal_status' => InvoiceFiscalStatus::Rejected, 'emission_mode' => InvoiceEmissionMode::OfflineDigital,
            'attempted_invoice_number' => 7788, 'cuf' => 'CUF-FILTRO-RECHAZADO-7788',
        ]);

        $this->actingAs($this->user)->get($this->dashboardUrl([
            'status' => InvoiceFiscalStatus::Rejected->value, 'modality' => InvoiceEmissionMode::OfflineDigital->value,
            'event_id' => $this->event->id, 'cuf' => 'FILTRO-RECHAZADO', 'number' => 7788,
            'client' => 'Rechazado Único', 'date_from' => today()->toDateString(), 'date_to' => today()->toDateString(),
        ]))->assertOk()->assertSee($rejected->cuf)->assertSee('Cliente Rechazado Único')->assertDontSee('Cliente Offline Visible');
    }

    public function test_company_user_cannot_monitor_records_from_another_company(): void
    {
        $this->grant($this->user, 'contingencies.view');
        $other = Company::factory()->create(['name' => 'Empresa Ajena Oculta']);
        SinInvoiceIssue::factory()->create(['company_id' => $other->id, 'cuf' => 'CUF-EMPRESA-AJENA']);

        $this->actingAs($this->user)->get($this->dashboardUrl(['company_id' => $other->id]))
            ->assertOk()->assertSee('Empresa Monitor SIAT')->assertDontSee('Empresa Ajena Oculta')->assertDontSee('CUF-EMPRESA-AJENA');
    }

    public function test_global_superadministrator_can_select_a_company_without_cross_company_mutation(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $global = User::factory()->create(['company_id' => null]);
        $global->assignRole('super_admin');

        $this->actingAs($global)->get($this->dashboardUrl())
            ->assertOk()->assertSee('Empresa Monitor SIAT')->assertSee('Cliente Offline Visible');
    }

    public function test_event_and_sanitized_technical_response_require_their_own_permissions(): void
    {
        $this->event->forceFill(['response' => ['token' => 'secret-value', 'codigoRecepcion' => 'EVENT-001']])->save();
        $this->grant($this->user, 'contingencies.events.view', 'contingencies.technical.view');

        $this->actingAs($this->user)->get(route('billing.contingencies.events.show', $this->event))
            ->assertOk()->assertSee('Corte de Internet monitoreado');
        $this->actingAs($this->user)->get(route('billing.contingencies.technical.show', ['event', $this->event->id]))
            ->assertOk()->assertSee('[REDACTADO]')->assertDontSee('secret-value')->assertSee('EVENT-001');
    }

    public function test_authorized_actions_only_dispatch_existing_idempotent_jobs(): void
    {
        Queue::fake();
        $this->grant($this->user,
            'contingencies.view', 'contingencies.events.retry', 'contingencies.packages.build',
            'contingencies.packages.send', 'contingencies.packages.validate',
        );

        $this->event->forceFill(['event_status' => SignificantEventStatus::PendingRegistration])->save();
        $this->actingAs($this->user)->post(route('billing.contingencies.events.retry', $this->event))->assertRedirect();
        $this->event->forceFill([
            'event_status' => SignificantEventStatus::Registered,
            'transaccion' => true,
            'reception_code' => 'EVENTO-REGISTRADO-ACCIONES',
        ])->save();
        $this->actingAs($this->user)->post(route('billing.contingencies.packages.build', $this->event))->assertRedirect();
        $this->actingAs($this->user)->post(route('billing.contingencies.packages.send', $this->package))->assertRedirect();
        $this->package->forceFill(['package_status' => InvoicePackageStatus::PendingValidation])->save();
        $this->actingAs($this->user)->post(route('billing.contingencies.packages.validate', $this->package))->assertRedirect();

        Queue::assertPushed(RegisterSignificantEventJob::class, 1);
        Queue::assertPushed(BuildContingencyPackagesJob::class, 1);
        Queue::assertPushed(SendContingencyPackageJob::class, 1);
        Queue::assertPushed(CheckPackageValidationJob::class, 1);
    }

    public function test_completed_event_returns_an_explanatory_warning_instead_of_http_409(): void
    {
        Queue::fake();
        $this->grant($this->user, 'contingencies.packages.build');
        $this->event->forceFill([
            'event_status' => SignificantEventStatus::Completed,
            'closed_at' => now(),
        ])->save();

        $this->actingAs($this->user)
            ->post(route('billing.contingencies.packages.build', $this->event))
            ->assertRedirect()
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'ya fue procesado'));

        Queue::assertNotPushed(BuildContingencyPackagesJob::class);
    }

    public function test_registered_event_without_eligible_offline_invoices_cannot_enqueue_package_generation(): void
    {
        Queue::fake();
        $this->grant($this->user, 'contingencies.packages.build');
        $this->event->forceFill([
            'event_status' => SignificantEventStatus::Registered,
            'transaccion' => true,
            'reception_code' => 'EVENTO-REGISTRADO-SIN-PENDIENTES',
        ])->save();
        $this->offlineInvoice->forceFill([
            'emission_mode' => InvoiceEmissionMode::Online,
            'fiscal_status' => InvoiceFiscalStatus::Validated,
        ])->save();

        $this->actingAs($this->user)
            ->post(route('billing.contingencies.packages.build', $this->event))
            ->assertRedirect()
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'no tiene facturas fuera de línea pendientes'));

        Queue::assertNotPushed(BuildContingencyPackagesJob::class);
    }

    public function test_communication_check_uses_service_and_never_calls_real_siat_in_test(): void
    {
        $this->grant($this->user, 'contingencies.communication.check');
        $this->mock(SiatCommunicationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->once()->andReturn(new SiatHealthCheckResult(
                available: true, errorType: SiatErrorType::Available,
                userMessage: 'Comunicación simulada disponible.', technicalMessage: 'simulada',
                operation: 'verificarComunicacion', wsdlUrl: 'https://simulado.test',
                durationMs: 10, requestDurationMs: 8, attempts: 1,
                shouldOpenContingency: false, checkedAt: now()->toIso8601String(), response: ['transaccion' => true],
            ));
        });

        $this->actingAs($this->user)->post(route('billing.contingencies.communication.check'), [
            'company_id' => $this->company->id, 'point_of_sale_id' => $this->point->id,
        ])->assertRedirect()->assertSessionHas('success', 'Comunicación simulada disponible.');
    }

    public function test_xml_download_is_read_only_and_scoped_to_company(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put($this->offlineInvoice->xml_path, '<factura>inmutable</factura>');
        $this->grant($this->user, 'contingencies.artifacts.download');

        $this->actingAs($this->user)->get(route('billing.contingencies.invoices.xml', $this->offlineInvoice))
            ->assertOk()->assertDownload('factura-'.$this->offlineInvoice->cuf.'.xml');

        $otherCompany = Company::factory()->create();
        $otherInvoice = SinInvoiceIssue::factory()->create(['company_id' => $otherCompany->id, 'xml_path' => 'other.xml']);
        Storage::disk('local')->put('other.xml', '<factura/>');
        $this->actingAs($this->user)->get('/facturacion/contingencias/facturas/'.$otherInvoice->id.'/xml')->assertNotFound();
    }

    public function test_invoice_xml_can_be_viewed_inline_from_the_general_invoice_module(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put($this->offlineInvoice->xml_path, '<factura>visible</factura>');
        $this->grant($this->user, 'invoices.view');

        $this->actingAs($this->user)
            ->get(route('billing.invoices.xml', $this->offlineInvoice))
            ->assertOk()
            ->assertHeader('content-type', 'application/xml; charset=UTF-8')
            ->assertHeader('content-disposition', 'inline; filename="factura-'.$this->offlineInvoice->invoice_number.'.xml"')
            ->assertSeeText('visible');
    }

    public function test_contingency_routes_expose_no_delete_or_fiscal_edit_endpoint(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'billing.contingencies.'));

        self::assertNotEmpty($routes);
        foreach ($routes as $route) {
            self::assertSame([], array_values(array_intersect($route->methods(), ['DELETE', 'PUT', 'PATCH'])));
        }
    }

    public function test_operational_roles_receive_distinct_contingency_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        self::assertTrue(Role::findByName('cashier')->hasPermissionTo('contingencies.view'));
        self::assertFalse(Role::findByName('cashier')->hasPermissionTo('contingencies.packages.send'));
        self::assertTrue(Role::findByName('branch_admin')->hasPermissionTo('contingencies.packages.send'));
        self::assertTrue(Role::findByName('accounting')->hasPermissionTo('contingencies.packages.validate'));
        self::assertTrue(Role::findByName('tax_responsible')->hasPermissionTo('contingencies.audit.view'));
        self::assertTrue(Role::findByName('technical_support')->hasPermissionTo('contingencies.technical.view'));
        self::assertFalse(Role::findByName('technical_support')->hasPermissionTo('contingencies.packages.build'));
        self::assertTrue(Role::findByName('super_admin')->hasPermissionTo('contingencies.communication.check'));
    }

    private function grant(User $user, string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }
        $user->givePermissionTo($permissions);
    }

    /** @param array<string, mixed> $extra */
    private function dashboardUrl(array $extra = []): string
    {
        return route('billing.contingencies.index', array_merge([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'point_of_sale_id' => $this->point->id,
        ], $extra));
    }
}
