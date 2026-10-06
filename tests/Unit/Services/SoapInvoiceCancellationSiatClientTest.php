<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SiatEnvironment;
use App\Enums\SiatModality;
use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinCufd;
use App\Models\SinCuis;
use App\Models\SinInvoiceIssue;
use App\Services\Billing\SoapInvoiceCancellationSiatClient;
use App\Services\Siat\SiatErrorClassifier;
use App\Services\Siat\SiatLogSanitizer;
use App\Services\Siat\SiatSoapClientFactory;
use Tests\TestCase;

final class SoapInvoiceCancellationSiatClientTest extends TestCase
{
    public function test_sends_official_purchase_sale_cancellation_payload(): void
    {
        $soap = new RecordingCancellationSoap;
        $factory = new class($soap) extends SiatSoapClientFactory
        {
            public function __construct(private readonly object $soap) {}

            public function make(string $wsdlUrl, string $apiToken, int $timeoutSeconds = 5): object
            {
                return $this->soap;
            }
        };
        $invoice = new SinInvoiceIssue([
            'environment_code' => SiatEnvironment::TestingAndPilot,
            'modality_code' => SiatModality::ComputerizedOnline,
            'tax_id' => '123456789', 'document_sector_code' => 1,
            'invoice_document_type_code' => 1, 'cuf' => 'CUF-FACTURA-10',
        ]);
        $cufd = new SinCufd([
            'branch_code' => 2, 'point_of_sale_code' => 4, 'cufd_code' => 'CUFD-VIGENTE',
        ]);
        $cufd->setRelation('apiToken', new SinApiToken(['api_token' => 'TOKEN']));
        $cufd->setRelation('authorization', new SinAuthorization(['system_code' => 'SISTEMA-1']));
        $cufd->setRelation('cuis', new SinCuis(['cuis_code' => 'CUIS-VIGENTE']));

        $response = (new SoapInvoiceCancellationSiatClient(
            $factory,
            new SiatErrorClassifier,
            new SiatLogSanitizer,
        ))->cancel($invoice, $cufd, 3);

        self::assertSame(905, data_get($response->data, 'RespuestaServicioFacturacion.codigoEstado'));
        self::assertSame([
            'codigoAmbiente' => 2,
            'codigoPuntoVenta' => 4,
            'codigoSistema' => 'SISTEMA-1',
            'codigoSucursal' => 2,
            'nit' => '123456789',
            'codigoDocumentoSector' => 1,
            'codigoEmision' => 1,
            'codigoModalidad' => 2,
            'cufd' => 'CUFD-VIGENTE',
            'cuis' => 'CUIS-VIGENTE',
            'tipoFacturaDocumento' => 1,
            'codigoMotivo' => 3,
            'cuf' => 'CUF-FACTURA-10',
        ], $soap->payload['SolicitudServicioAnulacionFactura']);
    }
}

final class RecordingCancellationSoap
{
    /** @var array<string, mixed> */
    public array $payload = [];

    public function anulacionFactura(array $payload): object
    {
        $this->payload = $payload;

        return (object) ['RespuestaServicioFacturacion' => (object) ['codigoEstado' => 905, 'transaccion' => true]];
    }
}
