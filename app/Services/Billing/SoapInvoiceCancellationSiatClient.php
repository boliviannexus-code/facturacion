<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SiatErrorType;
use App\Models\SinCufd;
use App\Models\SinInvoiceIssue;
use App\Services\Billing\Contracts\InvoiceCancellationSiatClient;
use App\Services\Siat\SiatErrorClassifier;
use App\Services\Siat\SiatLogSanitizer;
use App\Services\Siat\SiatSoapClientFactory;
use Throwable;

final readonly class SoapInvoiceCancellationSiatClient implements InvoiceCancellationSiatClient
{
    public function __construct(
        private SiatSoapClientFactory $clients,
        private SiatErrorClassifier $classifier,
        private SiatLogSanitizer $sanitizer,
        private ?InvoiceWsdlResolver $wsdls = null,
    ) {}

    public function cancel(SinInvoiceIssue $invoice, SinCufd $currentCufd, int $reasonCode): InvoiceSiatResponse
    {
        $currentCufd->loadMissing(['apiToken', 'authorization', 'cuis']);
        $token = $currentCufd->apiToken;

        if (! $token || ! $currentCufd->authorization || ! $currentCufd->cuis) {
            throw new InvoiceTransportException('La configuración fiscal para anular está incompleta.', false, SiatErrorType::LocalConfiguration);
        }

        try {
            $client = $this->clients->make(
                ($this->wsdls ?? new InvoiceWsdlResolver)->resolve((int) $invoice->document_sector_code, (int) $invoice->company_id),
                (string) $token->api_token,
                30,
            );
            $startedAt = microtime(true);
            $response = $client->anulacionFactura([
                'SolicitudServicioAnulacionFactura' => [
                    'codigoAmbiente' => $invoice->environment_code->value,
                    'codigoPuntoVenta' => $currentCufd->point_of_sale_code,
                    'codigoSistema' => (string) $currentCufd->authorization->system_code,
                    'codigoSucursal' => $currentCufd->branch_code,
                    'nit' => $invoice->tax_id,
                    'codigoDocumentoSector' => $invoice->document_sector_code,
                    'codigoEmision' => 1,
                    'codigoModalidad' => $invoice->modality_code->value,
                    'cufd' => $currentCufd->cufd_code,
                    'cuis' => (string) $currentCufd->cuis->cuis_code,
                    'tipoFacturaDocumento' => $invoice->invoice_document_type_code,
                    'codigoMotivo' => $reasonCode,
                    'cuf' => $invoice->cuf,
                ],
            ]);
        } catch (Throwable $exception) {
            throw new InvoiceTransportException(
                $this->sanitizer->text($exception->getMessage(), (string) $token?->api_token) ?? 'Error al comunicar la anulación al SIN.',
                isset($client),
                $this->classifier->classify($exception),
                $exception,
            );
        }

        $data = json_decode((string) json_encode($response), true);

        return new InvoiceSiatResponse(is_array($data) ? $data : ['value' => $response], (int) round((microtime(true) - $startedAt) * 1000));
    }
}
