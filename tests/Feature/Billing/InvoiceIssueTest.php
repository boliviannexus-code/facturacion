<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceFiscalStatus;
use App\Enums\SiatEnvironment;
use App\Enums\SiatFailureCategory;
use App\Jobs\ResendPendingOnlineInvoiceJob;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinBranch;
use App\Models\SinCatalogItem;
use App\Models\SinCufd;
use App\Models\SinCuis;
use App\Models\SinInvoiceIssue;
use App\Models\SinPointOfSale;
use App\Models\User;
use App\Services\Siat\SiatCommunicationResult;
use App\Services\Siat\SiatCommunicationService;
use App\Services\Siat\SiatSoapClientFactory;
use App\Services\Siat\SiatWsdlRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceIssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_menu_is_rendered_for_users_that_can_issue_invoices(): void
    {
        $user = $this->companyUser(['dashboard.view', 'invoices.issue']);

        $this
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Facturacion')
            ->assertSee('Emitir factura')
            ->assertSee('Eventos significativos')
            ->assertSee(route('billing.significant-events.index'))
            ->assertSee(route('billing.invoices.issue.index'));
    }

    public function test_invoice_list_identifies_the_document_sector_in_its_own_column(): void
    {
        $user = $this->companyUser(['invoices.view']);

        $this
            ->actingAs($user)
            ->get(route('billing.invoices.index'))
            ->assertOk()
            ->assertSee('Tipo de factura')
            ->assertSee('"data":"document_type"', false)
            ->assertSee('"name":"sin_invoice_issues.document_sector_code"', false);
    }

    public function test_pending_online_invoice_can_be_queued_for_resend_without_creating_another_invoice(): void
    {
        Queue::fake();
        $user = $this->companyUser(['invoices.issue']);
        $invoice = SinInvoiceIssue::factory()->create([
            'company_id' => $user->company_id,
            'fiscal_status' => InvoiceFiscalStatus::PendingOnlineSend,
        ]);

        $this->actingAs($user)
            ->post(route('billing.invoices.resend', $invoice))
            ->assertRedirect(route('billing.invoices.index'))
            ->assertSessionHas('success');

        Queue::assertPushed(ResendPendingOnlineInvoiceJob::class, fn (ResendPendingOnlineInvoiceJob $job): bool => $job->companyId === $user->company_id
            && $job->invoiceId === $invoice->id
            && $job->actorId === $user->id,
        );
        $this->assertDatabaseCount('sin_invoice_issues', 1);
    }

    public function test_uncertain_invoice_cannot_be_manually_resent(): void
    {
        Queue::fake();
        $user = $this->companyUser(['invoices.issue']);
        $invoice = SinInvoiceIssue::factory()->create([
            'company_id' => $user->company_id,
            'fiscal_status' => InvoiceFiscalStatus::UncertainSend,
        ]);

        $this->actingAs($user)
            ->post(route('billing.invoices.resend', $invoice))
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_pending_invoice_cannot_be_enqueued_twice_at_the_same_time(): void
    {
        Queue::fake();
        $user = $this->companyUser(['invoices.issue']);
        $invoice = SinInvoiceIssue::factory()->create([
            'company_id' => $user->company_id,
            'fiscal_status' => InvoiceFiscalStatus::PendingOnlineSend,
        ]);

        $this->actingAs($user)->post(route('billing.invoices.resend', $invoice));
        $this->actingAs($user)
            ->post(route('billing.invoices.resend', $invoice))
            ->assertRedirect(route('billing.invoices.index'))
            ->assertSessionHas('info');

        Queue::assertPushed(ResendPendingOnlineInvoiceJob::class, 1);
    }

    public function test_significant_event_can_be_registered_without_an_invoice(): void
    {
        $user = $this->companyUser(['invoices.issue']);
        [$apiToken, $authorization, $pointOfSale] = $this->siatConfiguration($user);
        $cuis = SinCuis::factory()->create([
            'company_id' => $user->company_id,
            'sin_api_token_id' => $apiToken->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'branch_code' => 7,
            'point_of_sale_code' => 3,
            'transaccion' => true,
            'cuis_code' => 'CUIS-INDEPENDIENTE-123',
        ]);
        $eventCufd = SinCufd::factory()->create([
            'company_id' => $user->company_id,
            'sin_api_token_id' => $apiToken->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'sin_cuis_id' => $cuis->id,
            'branch_code' => 7,
            'point_of_sale_code' => 3,
            'transaccion' => true,
            'cufd_code' => 'CUFD-INDEPENDIENTE-123',
            'expires_at' => now()->addDay(),
            'requested_at' => now()->subMinutes(15),
        ]);
        $currentCufd = SinCufd::factory()->create([
            'company_id' => $user->company_id,
            'sin_api_token_id' => $apiToken->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'sin_cuis_id' => $cuis->id,
            'branch_code' => 7,
            'point_of_sale_code' => 3,
            'transaccion' => true,
            'cufd_code' => 'CUFD-ACTUAL-456',
            'expires_at' => now()->addDay(),
            'requested_at' => now(),
        ]);
        $this->seedCatalogItem($user->company_id, 'eventos_significativos', '1', 'CORTE DEL SERVICIO DE INTERNET');
        $factory = new class extends SiatSoapClientFactory
        {
            public array $payloads = [];

            public function make(string $wsdlUrl, string $apiToken, int $timeoutSeconds = 5): object
            {
                return new class($this)
                {
                    public function __construct(private object $factory) {}

                    public function registroEventoSignificativo(array $payload): object
                    {
                        $this->factory->payloads[] = $payload;

                        return (object) ['RespuestaListaEventos' => (object) [
                            'codigoRecepcionEventoSignificativo' => 'EVT-INDEPENDIENTE-1',
                            'transaccion' => true,
                        ]];
                    }
                };
            }
        };
        $this->instance(SiatSoapClientFactory::class, $factory);

        $this->actingAs($user)
            ->get(route('billing.significant-events.index'))
            ->assertOk()
            ->assertSee('Registro independiente')
            ->assertSee('Sucursal 7 · PV 3')
            ->assertSee('CORTE DEL SERVICIO DE INTERNET');

        $this->actingAs($user)
            ->post(route('billing.significant-events.point-of-sale.store'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
                'event_code' => 1,
                'description' => 'Corte independiente del proveedor de internet.',
                'started_at' => now()->subMinutes(10)->format('Y-m-d H:i:s'),
                'ended_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect(route('billing.significant-events.index', ['point_of_sale_id' => $pointOfSale->id]))
            ->assertSessionHas('success');

        $this->assertSame('CUFD-INDEPENDIENTE-123', $factory->payloads[0]['SolicitudEventoSignificativo']['cufdEvento']);
        $this->assertSame('CUFD-ACTUAL-456', $factory->payloads[0]['SolicitudEventoSignificativo']['cufd']);
        $this->assertDatabaseHas('sin_significant_events', [
            'sin_invoice_issue_id' => null,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'event_status' => 'REGISTERED',
            'reception_code' => 'EVT-INDEPENDIENTE-1',
            'recovery_sin_cufd_id' => $currentCufd->id,
            'transaccion' => true,
        ]);
    }

    public function test_independent_significant_event_option_requires_invoice_issue_permission(): void
    {
        $user = $this->companyUser(['dashboard.view']);

        $this->actingAs($user)
            ->get(route('billing.significant-events.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('billing.significant-events.index'));
    }

    public function test_issue_index_lists_only_active_document_sector_types(): void
    {
        $user = $this->companyUser(['invoices.issue', 'customers.create']);

        $this->seedDocumentSector($user->company_id, '1', 'FACTURA COMPRA-VENTA');
        $this->seedDocumentSector($user->company_id, '2', 'FACTURA ALQUILER', isActive: false);

        $this
            ->actingAs($user)
            ->get(route('billing.invoices.issue.index'))
            ->assertOk()
            ->assertSee('FACTURA COMPRA-VENTA')
            ->assertSee(route('billing.invoices.issue.show', 1))
            ->assertDontSee('FACTURA ALQUILER');
    }

    public function test_purchase_sale_sector_renders_its_invoice_form(): void
    {
        $user = $this->companyUser(['invoices.issue']);
        $customer = Customer::factory()->create([
            'company_id' => $user->company_id,
            'name' => 'Cliente de prueba',
            'document_number' => '123456',
        ]);
        $product = Product::factory()->create([
            'company_id' => $user->company_id,
            'internal_code' => 'PRD-001',
            'description' => 'Producto homologado',
            'siat_product_code' => 99123,
        ]);

        $this->seedDocumentSector($user->company_id, '1', 'FACTURA COMPRA-VENTA');
        $this->seedCatalogItem($user->company_id, 'tipos_metodo_pago', '1', 'EFECTIVO');
        $this->seedCatalogItem($user->company_id, 'tipos_moneda', '1', 'BOLIVIANO');

        $this
            ->actingAs($user)
            ->get(route('billing.invoices.issue.show', 1))
            ->assertOk()
            ->assertSee('Factura compra-venta')
            ->assertDontSee('Datos basicos del contribuyente')
            ->assertDontSee('Casos especiales')
            ->assertSee('Datos de la transaccion comercial')
            ->assertSee('Cliente registrado')
            ->assertSee('Cliente nuevo')
            ->assertSee('Datos básicos del cliente')
            ->assertSee('Detalle de la transaccion comercial')
            ->assertSee($customer->name)
            ->assertSee('EFECTIVO')
            ->assertSee('BOLIVIANO')
            ->assertSee($product->internal_code)
            ->assertSee('Resumen')
            ->assertSee('id="invoice-issued-at" name="issued_at" type="hidden"', false)
            ->assertSee('Emitir factura');
    }

    public function test_zero_rate_sector_reuses_invoice_form_and_filters_catalogs(): void
    {
        $user = $this->companyUser(['invoices.issue']);
        $activityCode = '4761100';
        Product::factory()->create([
            'company_id' => $user->company_id,
            'internal_code' => 'LIBRO-001',
            'description' => 'Libro impreso tasa cero',
            'economic_activity_code' => $activityCode,
        ]);
        Product::factory()->create([
            'company_id' => $user->company_id,
            'internal_code' => 'OTRO-001',
            'description' => 'Producto fuera de tasa cero',
            'economic_activity_code' => '6201000',
        ]);
        $this->seedDocumentSector($user->company_id, '8', 'FACTURA TASA CERO');
        SinCatalogItem::factory()->create([
            'company_id' => $user->company_id,
            'catalog_key' => 'actividades',
            'item_key' => 'codigoCaeb:'.$activityCode,
            'classifier_code' => null,
            'description' => 'VENTA DE LIBROS',
            'raw_data' => ['codigoCaeb' => $activityCode, 'descripcion' => 'VENTA DE LIBROS', 'tipoActividad' => 'P'],
            'is_active' => true,
        ]);
        SinCatalogItem::factory()->create([
            'company_id' => $user->company_id,
            'catalog_key' => 'actividades_documento_sector',
            'item_key' => 'codigoActividad:'.$activityCode.'|codigoDocumentoSector:8',
            'classifier_code' => $activityCode,
            'description' => null,
            'raw_data' => ['codigoActividad' => $activityCode, 'codigoDocumentoSector' => 8, 'tipoDocumentoSector' => 'FTC'],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('billing.invoices.issue.show', 8))
            ->assertOk()
            ->assertSee('Factura Tasa Cero')
            ->assertSee('name="document_sector_code" type="hidden" value="8"', false)
            ->assertSee($activityCode)
            ->assertSee('VENTA DE LIBROS')
            ->assertSee('LIBRO-001')
            ->assertDontSee('OTRO-001')
            ->assertDontSee('Formulario en desarrollo');
    }

    public function test_purchase_sale_form_shows_green_fiscal_statuses_when_nit_cuis_and_cufd_are_ready(): void
    {
        $user = $this->companyUser(['invoices.issue']);
        [$apiToken, , $pointOfSale] = $this->siatConfiguration($user);

        SinCuis::factory()->create([
            'company_id' => $user->company_id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'branch_code' => $pointOfSale->branch->branch_code,
            'point_of_sale_code' => $pointOfSale->point_of_sale_code,
            'transaccion' => true,
            'cuis_code' => 'CUIS-CURRENT-123',
        ]);
        SinCufd::factory()->create([
            'company_id' => $user->company_id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'branch_code' => $pointOfSale->branch->branch_code,
            'point_of_sale_code' => $pointOfSale->point_of_sale_code,
            'transaccion' => true,
            'cufd_code' => 'CUFD-CURRENT-123',
            'expires_at' => now()->addDay(),
        ]);

        $this->mock(SiatCommunicationService::class, function (MockInterface $mock) use ($apiToken): void {
            $mock
                ->shouldReceive('verify')
                ->once()
                ->withArgs(fn (SinApiToken $token): bool => $token->is($apiToken))
                ->andReturn(new SiatCommunicationResult(
                    ok: true,
                    message: 'SIAT respondio correctamente.',
                    operation: 'verificarComunicacion',
                    wsdlUrl: $apiToken->wsdl_url,
                    durationMs: 90,
                    checkedAt: '30/07/2026 14:00:00',
                ));
        });

        $this->seedDocumentSector($user->company_id, '1', 'FACTURA COMPRA-VENTA');

        $this
            ->actingAs($user)
            ->get(route('billing.invoices.issue.show', 1))
            ->assertOk()
            ->assertSee('123456789')
            ->assertSee('CUIS')
            ->assertSee('CUFD')
            ->assertDontSee('CUIS vigente')
            ->assertDontSee('CUFD vigente')
            ->assertSee('data-communication-ok="1"', false)
            ->assertSee('data-cuis-valid="1"', false)
            ->assertSee('data-cufd-valid="1"', false)
            ->assertSee('data-cufd-request-url=', false)
            ->assertSee('data-refresh-cufd-on-selection="1"', false)
            ->assertDontSee('Solicitar CUFD');
    }

    public function test_production_keeps_current_cufd_reuse_behavior_when_selecting_a_point_of_sale(): void
    {
        $user = $this->companyUser(['invoices.issue']);
        [$apiToken, $authorization] = $this->siatConfiguration($user);
        $authorization->update(['environment_code' => SiatEnvironment::Production]);

        $this->mock(SiatCommunicationService::class, function (MockInterface $mock) use ($apiToken): void {
            $mock->shouldReceive('verify')->once()->andReturn(new SiatCommunicationResult(
                ok: true,
                message: 'SIAT respondio correctamente.',
                operation: 'verificarComunicacion',
                wsdlUrl: $apiToken->wsdl_url,
                durationMs: 90,
                checkedAt: '03/08/2026 14:00:00',
            ));
        });

        $this->seedDocumentSector($user->company_id, '1', 'FACTURA COMPRA-VENTA');

        $this->actingAs($user)
            ->get(route('billing.invoices.issue.show', 1))
            ->assertOk()
            ->assertSee('data-refresh-cufd-on-selection="0"', false);
    }

    public function test_invoice_cufd_request_uses_codes_wsdl_and_stores_successful_result(): void
    {
        $user = $this->companyUser(['invoices.issue']);
        [, , $pointOfSale] = $this->siatConfiguration($user);
        SinCuis::factory()->create([
            'company_id' => $user->company_id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'branch_code' => $pointOfSale->branch->branch_code,
            'point_of_sale_code' => $pointOfSale->point_of_sale_code,
            'transaccion' => true,
            'cuis_code' => 'CUIS-CURRENT-123',
        ]);
        $previousCufd = \App\Models\SinCufd::factory()->create([
            'company_id' => $user->company_id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'transaccion' => true,
            'cufd_code' => 'CUFD-ANTERIOR',
            'expires_at' => now()->addDay(),
        ]);

        $factory = new class extends SiatSoapClientFactory
        {
            public array $wsdlUrls = [];

            public function make(string $wsdlUrl, string $apiToken, int $timeoutSeconds = 5): object
            {
                $this->wsdlUrls[] = $wsdlUrl;

                return new class
                {
                    public function cufd(array $params): object
                    {
                        return (object) [
                            'RespuestaCufd' => (object) [
                                'codigo' => 'CUFD-OK-123456',
                                'codigoControl' => 'CTRL-123',
                                'direccion' => 'Av. Impuestos 123',
                                'fechaVigencia' => now()->addDay()->toIso8601String(),
                                'transaccion' => true,
                            ],
                        ];
                    }
                };
            }
        };

        $this->instance(SiatSoapClientFactory::class, $factory);

        $this
            ->actingAs($user)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->post(route('billing.invoices.issue.cufd.request'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cufd.status', 'Vigente')
            ->assertJsonPath('data.cufd.control_code', 'CTRL-123');

        $this->assertSame([SiatWsdlRegistry::CODES], $factory->wsdlUrls);
        self::assertNotNull($previousCufd->refresh()->invalidated_at);
        $this->assertDatabaseHas('sin_cufds', [
            'company_id' => $user->company_id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'cufd_code' => 'CUFD-OK-123456',
            'control_code' => 'CTRL-123',
            'transaccion' => true,
            'wsdl_url' => SiatWsdlRegistry::CODES,
        ]);
    }

    public function test_cufd_wsdl_network_failure_suggests_offline_issuance_and_reuses_current_cufd(): void
    {
        $user = $this->companyUser(['invoices.issue']);
        [, , $pointOfSale] = $this->siatConfiguration($user);
        $cuis = SinCuis::factory()->create([
            'company_id' => $user->company_id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'branch_code' => $pointOfSale->branch->branch_code,
            'point_of_sale_code' => $pointOfSale->point_of_sale_code,
            'transaccion' => true,
            'cuis_code' => 'CUIS-OFFLINE-123',
        ]);
        $currentCufd = \App\Models\SinCufd::factory()->create([
            'company_id' => $user->company_id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'sin_cuis_id' => $cuis->id,
            'branch_code' => $pointOfSale->branch->branch_code,
            'point_of_sale_code' => $pointOfSale->point_of_sale_code,
            'transaccion' => true,
            'cufd_code' => 'CUFD-VIGENTE-PARA-OFFLINE',
            'expires_at' => now()->addDay(),
            'requested_at' => now()->subMinute(),
        ]);
        $factory = new class extends SiatSoapClientFactory
        {
            public function make(string $wsdlUrl, string $apiToken, int $timeoutSeconds = 5): object
            {
                throw new \RuntimeException(
                    'SOAP-ERROR: Parsing WSDL: Could not load from URL: failed to load external entity',
                );
            }
        };
        $this->instance(SiatSoapClientFactory::class, $factory);

        $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->post(route('billing.invoices.issue.cufd.request'), [
                'sin_point_of_sale_id' => $pointOfSale->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('communication_ok', false)
            ->assertJsonPath('contingency_suggested', true)
            ->assertJsonPath('data.cufd.id', $currentCufd->id)
            ->assertJsonPath('data.cufd.is_current', true)
            ->assertJsonPath('message', 'No existe comunicación con el SIN. Puede continuar con la emisión fuera de línea; la factura quedará pendiente de regularización.');
    }

    public function test_other_active_document_sector_types_show_development_message(): void
    {
        $user = $this->companyUser(['invoices.issue']);

        $this->seedDocumentSector($user->company_id, '1', 'FACTURA COMPRA-VENTA');
        $this->seedDocumentSector($user->company_id, '43', 'FACTURA COMERCIAL DE EXPORTACION HIDROCARBUROS');

        $this
            ->actingAs($user)
            ->get(route('billing.invoices.issue.show', 43))
            ->assertOk()
            ->assertSee('Formulario en desarrollo')
            ->assertSee('FACTURA COMERCIAL DE EXPORTACION HIDROCARBUROS')
            ->assertSee('FACTURA COMPRA-VENTA');
    }

    public function test_inactive_document_sector_type_cannot_be_opened(): void
    {
        $user = $this->companyUser(['invoices.issue']);

        $this->seedDocumentSector($user->company_id, '1', 'FACTURA COMPRA-VENTA', isActive: false);

        $this
            ->actingAs($user)
            ->get(route('billing.invoices.issue.show', 1))
            ->assertNotFound();
    }

    public function test_role_permission_seeder_assigns_invoice_issue_permission_to_operational_roles(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue(Permission::query()->where('name', 'invoices.issue')->exists());
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('invoices.issue'));
        $this->assertTrue(Role::findByName('manager')->hasPermissionTo('invoices.issue'));
        $this->assertTrue(Role::findByName('cashier')->hasPermissionTo('invoices.issue'));
        $this->assertTrue(Role::findByName('cashier')->hasPermissionTo('customers.create'));
        $this->assertFalse(Role::findByName('viewer')->hasPermissionTo('invoices.issue'));
    }

    public function test_failed_invoice_can_register_a_significant_event_through_operations_service(): void
    {
        $user = $this->companyUser(['invoices.issue']);
        [$apiToken, $authorization, $pointOfSale] = $this->siatConfiguration($user);
        $cuis = SinCuis::factory()->create([
            'company_id' => $user->company_id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'branch_code' => 7,
            'point_of_sale_code' => 3,
            'transaccion' => true,
            'cuis_code' => 'CUIS-EVENT-123',
        ]);
        $cufd = SinCufd::factory()->create([
            'company_id' => $user->company_id,
            'sin_api_token_id' => $apiToken->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'sin_cuis_id' => $cuis->id,
            'branch_code' => 7,
            'point_of_sale_code' => 3,
            'transaccion' => true,
            'cufd_code' => 'CUFD-EVENT-123',
        ]);
        $customer = Customer::factory()->create(['company_id' => $user->company_id]);
        $invoice = SinInvoiceIssue::query()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'sin_api_token_id' => $apiToken->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $pointOfSale->sin_branch_id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'sin_cuis_id' => $cuis->id,
            'sin_cufd_id' => $cufd->id,
            'tax_id' => '123456789',
            'environment_code' => $authorization->environment_code,
            'modality_code' => $authorization->modality_code,
            'branch_code' => 7,
            'point_of_sale_code' => 3,
            'attempted_invoice_number' => 1,
            'cuf' => '12345678901234567890',
            'cufd_code' => 'CUFD-EVENT-123',
            'status_label' => 'Error',
            'fiscal_status' => InvoiceFiscalStatus::PendingOnlineSend,
            'failure_category' => SiatFailureCategory::Communication,
            'transaccion' => false,
            'issued_at' => now()->subMinutes(10),
        ]);
        $this->seedCatalogItem($user->company_id, 'eventos_significativos', '1', 'CORTE DEL SERVICIO DE INTERNET');

        $factory = new class extends SiatSoapClientFactory
        {
            public array $wsdlUrls = [];

            public array $payloads = [];

            public function make(string $wsdlUrl, string $apiToken, int $timeoutSeconds = 5): object
            {
                $this->wsdlUrls[] = $wsdlUrl;

                return new class($this)
                {
                    public function __construct(private object $factory) {}

                    public function registroEventoSignificativo(array $payload): object
                    {
                        $this->factory->payloads[] = $payload;

                        return (object) ['RespuestaListaEventos' => (object) [
                            'codigoRecepcionEventoSignificativo' => 'EVT-123',
                            'transaccion' => true,
                        ]];
                    }
                };
            }
        };
        $this->instance(SiatSoapClientFactory::class, $factory);

        $this->actingAs($user)
            ->get(route('billing.significant-events.create', $invoice))
            ->assertOk()
            ->assertSee('CORTE DEL SERVICIO DE INTERNET');

        $this->actingAs($user)
            ->post(route('billing.significant-events.store', $invoice), [
                'event_code' => 1,
                'description' => 'Corte de internet del proveedor.',
                'started_at' => now()->subMinutes(10)->format('Y-m-d H:i:s'),
                'ended_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect(route('billing.significant-events.create', $invoice))
            ->assertSessionHas('success');

        $this->assertSame([SiatWsdlRegistry::OPERATIONS], $factory->wsdlUrls);
        $this->assertSame(1, $factory->payloads[0]['SolicitudEventoSignificativo']['codigoMotivoEvento']);
        $this->assertSame('CUFD-EVENT-123', $factory->payloads[0]['SolicitudEventoSignificativo']['cufdEvento']);
        $this->assertDatabaseHas('sin_significant_events', [
            'sin_invoice_issue_id' => $invoice->id,
            'event_code' => 1,
            'reception_code' => 'EVT-123',
            'transaccion' => true,
            'status_label' => 'Registrado',
        ]);
    }

    private function companyUser(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function seedDocumentSector(
        int $companyId,
        string $code,
        string $description,
        bool $isActive = true
    ): SinCatalogItem {
        return $this->seedCatalogItem($companyId, 'tipos_documento_sector', $code, $description, $isActive);
    }

    private function seedCatalogItem(
        int $companyId,
        string $catalogKey,
        string $code,
        string $description,
        bool $isActive = true
    ): SinCatalogItem {
        return SinCatalogItem::factory()->create([
            'company_id' => $companyId,
            'catalog_key' => $catalogKey,
            'item_key' => 'codigoClasificador:'.$code,
            'classifier_code' => $code,
            'description' => $description,
            'is_active' => $isActive,
            'raw_data' => [
                'codigoClasificador' => $code,
                'descripcion' => $description,
            ],
        ]);
    }

    /**
     * @return array{SinApiToken, SinAuthorization, SinPointOfSale}
     */
    private function siatConfiguration(User $user): array
    {
        $branch = SinBranch::factory()->create([
            'company_id' => $user->company_id,
            'branch_code' => 7,
            'name' => 'Sucursal Centro',
            'is_active' => true,
        ]);
        $pointOfSale = SinPointOfSale::factory()->create([
            'company_id' => $user->company_id,
            'sin_branch_id' => $branch->id,
            'point_of_sale_code' => 3,
            'name' => 'Caja 3',
            'is_active' => true,
        ]);
        $apiToken = SinApiToken::factory()->create([
            'company_id' => $user->company_id,
            'api_token' => 'TOKEN-API-123456',
            'wsdl_url' => SiatWsdlRegistry::CODES,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
        ]);
        $authorization = SinAuthorization::factory()->create([
            'company_id' => $user->company_id,
            'tax_id' => '123456789',
            'system_code' => 'SYSTEM-CODE-123',
        ]);

        return [$apiToken, $authorization, $pointOfSale];
    }
}
