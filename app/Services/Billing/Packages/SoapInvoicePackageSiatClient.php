<?php

declare(strict_types=1);

namespace App\Services\Billing\Packages;

use App\Enums\SiatErrorType;
use App\Models\SinInvoicePackage;
use App\Services\Billing\InvoiceWsdlResolver;
use App\Services\Billing\Packages\Contracts\InvoicePackageSiatClient;
use App\Services\Siat\SiatDateTime;
use App\Services\Siat\SiatErrorClassifier;
use App\Services\Siat\SiatLogSanitizer;
use App\Services\Siat\SiatSoapClientFactory;
use SoapVar;
use Throwable;

final readonly class SoapInvoicePackageSiatClient implements InvoicePackageSiatClient
{
    public function __construct(
        private SiatSoapClientFactory $clients,
        private SiatErrorClassifier $classifier,
        private SiatLogSanitizer $sanitizer,
        private SiatPackageStatusMapper $statusMapper,
        private ?InvoiceWsdlResolver $wsdls = null,
    ) {}

    public function send(SinInvoicePackage $package, string $archive): PackageReceptionResult
    {
        $this->loadConfiguration($package);
        $token = (string) $package->apiToken->api_token;
        $client = $this->client($package, $token);
        $startedAt = microtime(true);
        $payload = [
            'SolicitudServicioRecepcionPaquete' => [
                ...$this->basePayload($package),
                'archivo' => new SoapVar($archive, XSD_BASE64BINARY),
                'fechaEnvio' => SiatDateTime::extended(now()),
                'hashArchivo' => $package->file_hash,
                'cantidadFacturas' => $package->invoice_count,
                'codigoEvento' => (string) $package->significantEvent->reception_code,
                ...($package->cafc_code ? ['cafc' => $package->cafc_code] : []),
            ],
        ];

        try {
            $response = $this->normalize($client->recepcionPaqueteFactura($payload));
        } catch (Throwable $exception) {
            $errorType = $this->classifier->classify($exception);
            throw new PackageTransportException(
                $this->sanitizer->text($exception->getMessage(), $token) ?? 'Error enviando el paquete a SIAT.',
                $this->mayHaveReachedSiat($errorType),
                $errorType,
                $exception,
            );
        }

        $receptionCode = $this->findValue($response, ['codigoRecepcion']);
        $statusCode = $this->findInt($response, ['codigoEstado']);
        $messages = $this->messageRows($response);
        $transaction = $this->findBool($response, ['transaccion']);
        $accepted = filled($receptionCode) && $transaction !== false;

        return new PackageReceptionResult(
            accepted: $accepted,
            receptionCode: $receptionCode,
            statusCode: $statusCode,
            message: $this->message($messages, $accepted
                ? 'Paquete recibido por SIAT y pendiente de validacion.'
                : 'SIAT no recibio el paquete.'),
            response: $response,
            messages: $messages,
            durationMs: $this->durationMs($startedAt),
        );
    }

    public function checkValidation(SinInvoicePackage $package): PackageValidationResult
    {
        $this->loadConfiguration($package);
        $token = (string) $package->apiToken->api_token;
        $client = $this->client($package, $token);
        $startedAt = microtime(true);
        $payload = [
            'SolicitudServicioValidacionRecepcionPaquete' => [
                ...$this->basePayload($package),
                'codigoRecepcion' => (string) $package->reception_code,
            ],
        ];

        try {
            $response = $this->normalize($client->validacionRecepcionPaqueteFactura($payload));
        } catch (Throwable $exception) {
            throw new PackageTransportException(
                $this->sanitizer->text($exception->getMessage(), $token) ?? 'Error consultando el paquete en SIAT.',
                false,
                $this->classifier->classify($exception),
                $exception,
            );
        }

        $statusCode = $this->findInt($response, ['codigoEstado']);
        $messages = $this->messageRows($response);
        $outcome = $this->statusMapper->outcome($statusCode);

        return new PackageValidationResult(
            outcome: $outcome,
            statusCode: $statusCode,
            message: $this->message($messages, 'SIAT devolvio el estado del paquete.'),
            response: $response,
            messages: $messages,
            invoiceResults: $this->invoiceResults($response),
            durationMs: $this->durationMs($startedAt),
        );
    }

    private function loadConfiguration(SinInvoicePackage $package): void
    {
        $package->loadMissing([
            'apiToken', 'authorization', 'branch', 'pointOfSale', 'cuis', 'cufd', 'significantEvent',
        ]);

        foreach (['apiToken', 'authorization', 'branch', 'pointOfSale', 'cuis', 'cufd', 'significantEvent'] as $relation) {
            $model = $package->getRelation($relation);

            if (! $model || (int) $model->company_id !== (int) $package->company_id) {
                throw new PackageTransportException(
                    'La configuracion fiscal del paquete esta incompleta o pertenece a otra empresa.',
                    false,
                    SiatErrorType::LocalConfiguration,
                );
            }
        }

        if (blank($package->significantEvent->reception_code)) {
            throw new PackageTransportException(
                'El evento significativo aun no tiene codigo de recepcion.',
                false,
                SiatErrorType::LocalConfiguration,
            );
        }
    }

    private function client(SinInvoicePackage $package, string $token): object
    {
        try {
            return $this->clients->make(
                ($this->wsdls ?? new InvoiceWsdlResolver)->resolve((int) $package->document_sector_code, (int) $package->company_id),
                $token,
                30,
            );
        } catch (Throwable $exception) {
            throw new PackageTransportException(
                $this->sanitizer->text($exception->getMessage(), $token) ?? 'No se pudo crear el transporte SIAT.',
                false,
                $this->classifier->classify($exception),
                $exception,
            );
        }
    }

    /** @return array<string, int|string> */
    private function basePayload(SinInvoicePackage $package): array
    {
        return [
            'codigoAmbiente' => $package->environment_code->value,
            'codigoDocumentoSector' => $package->document_sector_code,
            'codigoEmision' => $package->emission_type_code,
            'codigoModalidad' => $package->modality_code->value,
            'codigoPuntoVenta' => $package->point_of_sale_code,
            'codigoSistema' => (string) $package->authorization->system_code,
            'codigoSucursal' => $package->branch_code,
            'cufd' => (string) $package->cufd->cufd_code,
            'cuis' => (string) $package->cuis->cuis_code,
            'nit' => (string) $package->tax_id,
            'tipoFacturaDocumento' => $package->invoice_document_type_code,
        ];
    }

    /** @return array<string, mixed> */
    private function normalize(mixed $response): array
    {
        $decoded = json_decode((string) json_encode($response), true);

        return is_array($decoded) ? $decoded : ['value' => $response];
    }

    /** @param array<int, string> $keys */
    private function findValue(array $data, array $keys): ?string
    {
        foreach ($data as $key => $value) {
            if (in_array($key, $keys, true) && is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }

            if (is_array($value) && ($found = $this->findValue($value, $keys)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<int, string> $keys */
    private function findInt(array $data, array $keys): ?int
    {
        $value = $this->findValue($data, $keys);

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<int, string> $keys */
    private function findBool(array $data, array $keys): ?bool
    {
        foreach ($data as $key => $value) {
            if (in_array($key, $keys, true)) {
                if (is_bool($value)) {
                    return $value;
                }

                if (is_scalar($value)) {
                    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                }
            }

            if (is_array($value) && ($found = $this->findBool($value, $keys)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function messageRows(array $data): array
    {
        $rows = [];

        foreach ($data as $key => $value) {
            if (is_array($value) && in_array($key, ['mensajesList', 'mensajes', 'mensajeList'], true)) {
                foreach (array_is_list($value) ? $value : [$value] as $row) {
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                }
            } elseif (is_array($value)) {
                $rows = [...$rows, ...$this->messageRows($value)];
            }
        }

        return $rows;
    }

    /** @return array<int, PackageInvoiceValidationResult> */
    private function invoiceResults(array $data): array
    {
        $results = [];

        foreach ($data as $value) {
            if (! is_array($value)) {
                continue;
            }

            $cuf = $this->directValue($value, ['cuf', 'codigoCuf']);
            $statusCode = $this->directInt($value, ['codigoEstado']);

            if ($cuf !== null && $statusCode !== null) {
                $results[] = new PackageInvoiceValidationResult(
                    cuf: $cuf,
                    outcome: $this->statusMapper->outcome($statusCode),
                    statusCode: $statusCode,
                    message: $this->message($this->messageRows($value), 'Resultado individual devuelto por SIAT.'),
                    rawData: $value,
                );
            }

            $results = [...$results, ...$this->invoiceResults($value)];
        }

        return collect($results)->unique(fn (PackageInvoiceValidationResult $result): string => $result->cuf)->values()->all();
    }

    /** @param array<int, string> $keys */
    private function directValue(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        return null;
    }

    /** @param array<int, string> $keys */
    private function directInt(array $data, array $keys): ?int
    {
        $value = $this->directValue($data, $keys);

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<int, array<string, mixed>> $messages */
    private function message(array $messages, string $fallback): string
    {
        foreach ($messages as $row) {
            $message = trim((string) ($row['descripcion'] ?? $row['mensaje'] ?? $row['descripcionMensaje'] ?? ''));

            if ($message !== '') {
                return $message;
            }
        }

        return $fallback;
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function mayHaveReachedSiat(SiatErrorType $errorType): bool
    {
        return in_array($errorType, [
            SiatErrorType::Timeout,
            SiatErrorType::InvalidHttpResponse,
            SiatErrorType::Unknown,
        ], true);
    }
}
