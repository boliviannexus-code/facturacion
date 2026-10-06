<?php

declare(strict_types=1);

namespace App\Services\Siat\Recovery;

use App\Services\Siat\Recovery\Contracts\SignificantEventRegistrar;
use App\Services\Siat\SiatSoapClientFactory;

final readonly class SoapSignificantEventRegistrar implements SignificantEventRegistrar
{
    public function __construct(private SiatSoapClientFactory $clients) {}

    public function register(SignificantEventRegistrationRequest $request): SignificantEventRegistrationResult
    {
        $startedAt = microtime(true);
        $client = $this->clients->make($request->operationsWsdl, $request->apiToken, 30);
        $response = $this->normalize($client->registroEventoSignificativo($request->payload()));
        $transaction = $this->findBoolean($response, 'transaccion') === true;
        $receptionCode = $this->findValue($response, [
            'codigoRecepcionEventoSignificativo',
            'codigoRecepcionEvento',
            'codigoRecepcion',
        ]);
        $accepted = $transaction && filled($receptionCode);
        $message = $this->findValue($response, ['descripcion', 'mensaje', 'descripcionMensaje'])
            ?: match (true) {
                $accepted => 'Evento significativo registrado en el SIAT.',
                $transaction => 'SIAT respondió transaccion=true, pero no devolvió codigoRecepcion; el registro no está confirmado.',
                default => 'SIAT no aceptó el registro del evento significativo.',
            };

        return new SignificantEventRegistrationResult(
            successful: $accepted,
            receptionCode: $receptionCode,
            message: $message,
            response: $response,
            messages: $this->messageRows($response),
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    /** @return array<string, mixed> */
    private function normalize(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        $encoded = json_encode($response);
        $decoded = is_string($encoded) ? json_decode($encoded, true) : null;

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

    private function findBoolean(array $data, string $key): ?bool
    {
        foreach ($data as $currentKey => $value) {
            if ($currentKey === $key) {
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }

            if (is_array($value) && ($found = $this->findBoolean($value, $key)) !== null) {
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
            if (is_array($value) && in_array($key, ['mensajesList', 'mensajes', 'listaMensajes'], true)) {
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
}
