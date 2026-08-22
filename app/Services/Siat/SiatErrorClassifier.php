<?php

declare(strict_types=1);

namespace App\Services\Siat;

use App\Enums\SiatErrorType;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SiatErrorClassifier
{
    public function classify(Throwable $exception): SiatErrorType
    {
        if ($exception instanceof QueryException) {
            return SiatErrorType::Database;
        }

        if ($exception instanceof ValidationException) {
            return $this->classifyValidation($exception);
        }

        return $this->classifyMessage($exception->getMessage(), (int) $exception->getCode());
    }

    /** @param array<string, mixed> $response */
    public function classifyResponse(array $response): SiatErrorType
    {
        if ($this->findTransaction($response) === true) {
            return SiatErrorType::Available;
        }

        return $this->classifyMessage($this->flatten($response));
    }

    public function userMessage(SiatErrorType $errorType): string
    {
        return match ($errorType) {
            SiatErrorType::Available => 'La comunicacion con SIAT esta disponible.',
            SiatErrorType::NoInternet => 'No hay conexion a internet. Verifica la red e intenta nuevamente.',
            SiatErrorType::Timeout => 'SIAT no respondio dentro del tiempo esperado.',
            SiatErrorType::DnsUnavailable => 'No se pudo localizar el servidor de SIAT.',
            SiatErrorType::SiatUnavailable => 'El servicio de SIAT no esta accesible en este momento.',
            SiatErrorType::InvalidHttpResponse => 'SIAT devolvio una respuesta de comunicacion no valida.',
            SiatErrorType::InvalidToken => 'El token de acceso al SIAT es invalido o no esta vigente.',
            SiatErrorType::InvalidCuis => 'El CUIS es invalido o no esta vigente.',
            SiatErrorType::InvalidCufd => 'El CUFD es invalido o esta vencido.',
            SiatErrorType::ExpiredCertificate => 'El certificado digital esta vencido.',
            SiatErrorType::InvalidXml => 'El XML fue rechazado o no cumple el formato requerido.',
            SiatErrorType::CatalogError => 'Existe un problema con los catalogos de SIAT.',
            SiatErrorType::AuthenticationError => 'SIAT rechazo las credenciales de autenticacion.',
            SiatErrorType::LocalConfiguration => 'La configuracion local de SIAT esta incompleta o es invalida.',
            SiatErrorType::Database => 'No se pudo registrar o consultar la informacion local.',
            SiatErrorType::Unknown => 'Ocurrio un error no identificado al comunicarse con SIAT.',
        };
    }

    private function classifyValidation(ValidationException $exception): SiatErrorType
    {
        $fields = array_keys($exception->errors());

        return match (true) {
            in_array('xml', $fields, true) => SiatErrorType::InvalidXml,
            in_array('api_token', $fields, true), in_array('token', $fields, true) => SiatErrorType::InvalidToken,
            in_array('cufd', $fields, true) => SiatErrorType::InvalidCufd,
            in_array('cuis', $fields, true) => SiatErrorType::InvalidCuis,
            in_array('catalog', $fields, true) => SiatErrorType::CatalogError,
            default => SiatErrorType::LocalConfiguration,
        };
    }

    private function classifyMessage(string $rawMessage, int $code = 0): SiatErrorType
    {
        $message = mb_strtolower($rawMessage);

        return match (true) {
            $this->contains($message, ['certificate has expired', 'certificate expired', 'certificado digital vencido', 'certificado vencido']) => SiatErrorType::ExpiredCertificate,
            $this->contains($message, ['timed out', 'timeout', 'tiempo de espera', 'operation timed out']) => SiatErrorType::Timeout,
            $this->contains($message, ['could not resolve host', 'name or service not known', 'temporary failure in name resolution', 'dns']) => SiatErrorType::DnsUnavailable,
            $this->contains($message, ['network is unreachable', 'no route to host', 'sin conexion a internet', 'no internet', 'offline']) => SiatErrorType::NoInternet,
            $this->contains($message, ['token invalido', 'token inválido', 'token vencido', 'invalid token', 'api token']) => SiatErrorType::InvalidToken,
            $this->contains($message, ['cufd vencido', 'cufd invalido', 'cufd inválido', 'invalid cufd', 'expired cufd']) => SiatErrorType::InvalidCufd,
            $this->contains($message, ['cuis invalido', 'cuis inválido', 'invalid cuis', 'expired cuis']) => SiatErrorType::InvalidCuis,
            $this->contains($message, ['xml invalido', 'xml inválido', 'invalid xml', 'xml rechazado', 'xml rejected', 'schema validation']) => SiatErrorType::InvalidXml,
            $this->contains($message, ['catalogo', 'catálogo', 'catalog error', 'sincronizacion de catalogo']) => SiatErrorType::CatalogError,
            $code === 401, $code === 403,
            $this->contains($message, ['unauthorized', 'forbidden', 'authentication failed', 'autenticacion rechazada', 'credenciales invalidas']) => SiatErrorType::AuthenticationError,
            $this->contains($message, [
                'connection refused', 'service unavailable', 'internal server error',
                'bad gateway', 'gateway timeout', 'http 500', 'http 502', 'http 503', 'http 504',
                'failed to open stream', 'error fetching http headers', 'connection reset', 'broken pipe',
                "couldn't load from", 'could not load from', 'failed to load external entity',
            ]) => SiatErrorType::SiatUnavailable,
            $code >= 400,
            $this->contains($message, ['invalid http', 'http response', 'malformed response', 'respuesta http invalida']) => SiatErrorType::InvalidHttpResponse,
            $this->contains($message, ['soap extension', 'class "soapclient" not found', 'wsdl no configurado', 'missing configuration', 'configuracion local']) => SiatErrorType::LocalConfiguration,
            default => SiatErrorType::Unknown,
        };
    }

    /** @param array<int, string> $needles */
    private function contains(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $data */
    private function findTransaction(array $data): ?bool
    {
        foreach ($data as $key => $value) {
            if (mb_strtolower((string) $key) === 'transaccion') {
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }

            if (is_array($value) && ($transaction = $this->findTransaction($value)) !== null) {
                return $transaction;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function flatten(array $data): string
    {
        $values = [];
        array_walk_recursive($data, static function (mixed $value) use (&$values): void {
            if (is_scalar($value) || $value instanceof \Stringable) {
                $values[] = (string) $value;
            }
        });

        return implode(' ', $values);
    }
}
