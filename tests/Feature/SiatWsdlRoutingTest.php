<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiatEnvironment;
use App\Models\SinCuis;
use App\Models\SinInvoiceIssue;
use App\Models\SinInvoicePackage;
use App\Models\SinWsdlService;
use App\Services\Billing\Packages\SoapInvoicePackageSiatClient;
use App\Services\Billing\SoapInvoiceCancellationReversalSiatClient;
use App\Services\Billing\SoapInvoiceCancellationSiatClient;
use App\Services\Billing\SoapInvoiceSiatClient;
use App\Services\Siat\Recovery\SignificantEventRegistrationRequest;
use App\Services\Siat\Recovery\SoapSignificantEventRegistrar;
use App\Services\Siat\SiatCatalogSyncService;
use App\Services\Siat\SiatCufdService;
use App\Services\Siat\SiatCuisService;
use App\Services\Siat\SiatPointOfSaleService;
use App\Services\Siat\SiatSoapClientFactory;
use App\Services\Siat\SiatWsdlResolver;
use App\Services\Siat\SoapSiatCommunicationClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ConfiguresSiatWsdls;
use Tests\TestCase;

final class SiatWsdlRoutingTest extends TestCase
{
    use ConfiguresSiatWsdls;
    use RefreshDatabase;

    public function test_invoice_clients_use_frozen_environment_after_company_switches_to_production(): void
    {
        $invoice = SinInvoiceIssue::factory()->create();
        $invoice->authorization->update(['environment_code' => SiatEnvironment::Production]);
        $factory = $this->recordSoap();

        app(SoapInvoiceSiatClient::class)->send($invoice, 'compressed-xml');
        app(SoapInvoiceCancellationSiatClient::class)->cancel($invoice, $invoice->cufd, 1);
        app(SoapInvoiceCancellationReversalSiatClient::class)->reverse($invoice, $invoice->cufd);
        foreach ($factory->urls as $url) {
            self::assertSame('https://pilotosiatservicios.impuestos.gob.bo/v2/ServicioFacturacionCompraVenta?wsdl', $url);
        }
        self::assertCount(3, $factory->urls);
        foreach ($factory->payloads as $payload) {
            self::assertSame(2, array_values($payload)[0]['codigoAmbiente']);
        }
    }

    public function test_codes_catalogs_and_communication_use_company_production_configuration_not_token_url(): void
    {
        $invoice = SinInvoiceIssue::factory()->create();
        $invoice->authorization->update(['environment_code' => SiatEnvironment::Production]);
        $invoice->cuis->update(['environment_code' => SiatEnvironment::Production]);
        $invoice->pointOfSale->update(['is_active' => true]);
        $invoice->pointOfSale->branch->update(['is_active' => true]);
        SinWsdlService::where('company_id', $invoice->company_id)->where('environment_code', 1)->update(['is_active' => true]);
        $factory = $this->recordSoap();
        $user = $invoice->user;
        // No authenticated user: workers must resolve using the explicit company.
        app(SiatCuisService::class)->request($user, $invoice->pointOfSale);
        app(SiatCufdService::class)->requestWithConfiguration($user, $invoice->pointOfSale, $invoice->apiToken, $invoice->authorization, $invoice->cuis);
        app(SiatCatalogSyncService::class)->sync($user, 'actividades', $invoice->pointOfSale);
        app(SoapSiatCommunicationClient::class)->verify($invoice->apiToken, 5);

        self::assertSame([
            'https://siatrest.impuestos.gob.bo/v2/FacturacionCodigos?wsdl',
            'https://siatrest.impuestos.gob.bo/v2/FacturacionCodigos?wsdl',
            'https://siatrest.impuestos.gob.bo/v2/FacturacionSincronizacion?wsdl',
            'https://siatrest.impuestos.gob.bo/v2/FacturacionCodigos?wsdl',
        ], $factory->urls);
        $this->assertDatabaseHas('sin_cuis', ['company_id' => $invoice->company_id, 'environment_code' => 1, 'wsdl_url' => $factory->urls[0]]);
        $this->assertDatabaseHas('sin_cufds', ['company_id' => $invoice->company_id, 'environment_code' => 1, 'wsdl_url' => $factory->urls[1]]);
    }

    public function test_point_of_sale_and_event_clients_use_the_explicit_operations_service(): void
    {
        $invoice = SinInvoiceIssue::factory()->create();
        $invoice->authorization->update(['environment_code' => SiatEnvironment::Production]);
        SinCuis::factory()->create([
            'company_id' => $invoice->company_id, 'sin_api_token_id' => $invoice->sin_api_token_id,
            'sin_authorization_id' => $invoice->sin_authorization_id, 'sin_branch_id' => $invoice->sin_branch_id,
            'sin_point_of_sale_id' => null, 'point_of_sale_code' => 0, 'environment_code' => 1,
        ]);
        SinWsdlService::where('company_id', $invoice->company_id)->where('environment_code', 1)->where('key', 'operations')
            ->update(['is_active' => true, 'url' => 'https://siatrest.impuestos.gob.bo/sfd/FacturacionOperaciones?wsdl']);
        $factory = $this->recordSoap();
        app(SiatPointOfSaleService::class)->register($invoice->pointOfSale->branch, [
            'point_of_sale_type_code' => 1, 'name' => 'Punto registrado', 'description' => 'Prueba simulada',
        ]);
        $request = new SignificantEventRegistrationRequest(
            apiToken: 'SIMULATED',
            operationsWsdl: app(SiatWsdlResolver::class)->resolve($invoice->company_id, SiatEnvironment::Production, 'operations'),
            environmentCode: 1, eventCode: 1, pointOfSaleCode: 0, systemCode: 'SYSTEM', branchCode: 0,
            currentCufd: 'CURRENT', eventCufd: 'EVENT', cuis: 'CUIS', description: 'Prueba simulada',
            endedAt: now(), startedAt: now()->subMinute(), taxId: '123456789',
        );
        app(SoapSignificantEventRegistrar::class)->register($request);
        self::assertSame(array_fill(0, 2, 'https://siatrest.impuestos.gob.bo/sfd/FacturacionOperaciones?wsdl'), $factory->urls);
        foreach ($factory->payloads as $payload) {
            self::assertSame(1, array_values($payload)[0]['codigoAmbiente']);
        }
    }

    public function test_zero_rate_package_uses_its_own_environment_and_preserves_sfd(): void
    {
        $package = SinInvoicePackage::factory()->create(['document_sector_code' => 8]);
        SinWsdlService::where('company_id', $package->company_id)->where('environment_code', 2)->where('key', 'zero_rate_invoice')
            ->update(['url' => 'https://pilotosiatservicios.impuestos.gob.bo/sfd/ServicioFacturacionComputarizada?wsdl']);
        $package->significantEvent->update(['reception_code' => 'EVENTO']);
        $package->authorization->update(['environment_code' => SiatEnvironment::Production]);
        $factory = $this->recordSoap();
        app(SoapInvoicePackageSiatClient::class)->send($package, 'archive');
        app(SoapInvoicePackageSiatClient::class)->checkValidation($package);
        self::assertSame(array_fill(0, 2, 'https://pilotosiatservicios.impuestos.gob.bo/sfd/ServicioFacturacionComputarizada?wsdl'), $factory->urls);
    }

    public function test_missing_codes_blocks_before_creating_any_soap_client(): void
    {
        $invoice = SinInvoiceIssue::factory()->create();
        SinWsdlService::where('company_id', $invoice->company_id)->where('key', 'codes')->update(['is_active' => false]);
        $factory = $this->recordSoap();
        try {
            app(SiatCuisService::class)->request($invoice->user, $invoice->pointOfSale);
            self::fail('Debe bloquear la configuración incompleta.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('wsdl_url', $exception->errors());
        }
        self::assertSame([], $factory->urls);
    }

    private function recordSoap(): RecordingWsdlFactory
    {
        $factory = new RecordingWsdlFactory;
        $this->app->instance(SiatSoapClientFactory::class, $factory);

        return $factory;
    }
}

final class RecordingWsdlFactory extends SiatSoapClientFactory
{
    public array $urls = [];

    public array $payloads = [];

    public function make(string $wsdlUrl, string $apiToken, int $timeoutSeconds = 5): object
    {
        $this->urls[] = $wsdlUrl;

        return new class($this)
        {
            public function __construct(private RecordingWsdlFactory $factory) {}

            public function __call(string $method, array $arguments): object
            {
                if (isset($arguments[0])) {
                    $this->factory->payloads[] = $arguments[0];
                }

                return (object) ['RespuestaServicioFacturacion' => (object) [
                    'transaccion' => true, 'codigo' => 'CODIGOGENERADO',
                    'codigoControl' => 'CONTROL', 'fechaVigencia' => now()->addDay()->toIso8601String(),
                    'codigoEstado' => 908, 'codigoRecepcion' => 'RECEPCION', 'codigoPuntoVenta' => 7,
                ]];
            }
        };
    }
}
