<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SiatErrorType;
use App\Models\SinInvoiceIssue;
use App\Services\Billing\Contracts\InvoiceSiatClient;
use App\Services\Siat\SiatDateTime;
use App\Services\Siat\SiatErrorClassifier;
use App\Services\Siat\SiatLogSanitizer;
use App\Services\Siat\SiatSoapClientFactory;
use SoapVar;
use Throwable;

final readonly class SoapInvoiceSiatClient implements InvoiceSiatClient
{
    public function __construct(
        private SiatSoapClientFactory $clients,
        private SiatErrorClassifier $classifier,
        private SiatLogSanitizer $sanitizer,
        private ?InvoiceWsdlResolver $wsdls = null,
    ) {}

    public function send(SinInvoiceIssue $invoice, string $compressedXml): InvoiceSiatResponse
    {
        $invoice->loadMissing(['authorization', 'pointOfSale.branch', 'cuis', 'cufd']);
        $token = $invoice->apiToken()->first();

        if (! $token || ! $invoice->authorization || ! $invoice->pointOfSale?->branch || ! $invoice->cuis || ! $invoice->cufd) {
            throw new InvoiceTransportException(
                'La configuracion fiscal de la factura esta incompleta.',
                false,
                SiatErrorType::LocalConfiguration,
            );
        }

        try {
            $client = $this->clients->make(
                ($this->wsdls ?? new InvoiceWsdlResolver)->resolve((int) $invoice->document_sector_code, (int) $invoice->company_id),
                (string) $token->api_token,
                30,
            );
        } catch (Throwable $exception) {
            throw new InvoiceTransportException(
                $this->sanitizer->text($exception->getMessage(), (string) $token->api_token) ?? 'Error de transporte SIAT.',
                false,
                $this->classifier->classify($exception),
                $exception,
            );
        }

        $startedAt = microtime(true);
        $payload = [
            'SolicitudServicioRecepcionFactura' => [
                'codigoAmbiente' => $invoice->environment_code->value,
                'codigoDocumentoSector' => $invoice->document_sector_code,
                'codigoEmision' => $invoice->emission_type_code,
                'codigoModalidad' => $invoice->modality_code->value,
                'codigoPuntoVenta' => $invoice->point_of_sale_code,
                'codigoSistema' => (string) $invoice->authorization->system_code,
                'codigoSucursal' => $invoice->branch_code,
                'cufd' => $invoice->cufd_code,
                'cuis' => (string) $invoice->cuis->cuis_code,
                'nit' => $invoice->tax_id,
                'tipoFacturaDocumento' => $invoice->invoice_document_type_code,
                'archivo' => new SoapVar($compressedXml, XSD_BASE64BINARY),
                'fechaEnvio' => SiatDateTime::extended(now()),
                'hashArchivo' => $invoice->hash_file,
            ],
        ];

        try {
            $response = $client->recepcionFactura($payload);
        } catch (Throwable $exception) {
            throw new InvoiceTransportException(
                $this->sanitizer->text($exception->getMessage(), (string) $token->api_token) ?? 'Error de transporte SIAT.',
                true,
                $this->classifier->classify($exception),
                $exception,
            );
        }

        return new InvoiceSiatResponse(
            data: $this->normalize($response),
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    /** @return array<string, mixed> */
    private function normalize(mixed $response): array
    {
        $decoded = json_decode((string) json_encode($response), true);

        return is_array($decoded) ? $decoded : ['value' => $response];
    }
}
