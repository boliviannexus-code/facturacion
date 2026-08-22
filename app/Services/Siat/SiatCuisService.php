<?php

namespace App\Services\Siat;

use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinCuis;
use App\Models\SinPointOfSale;
use App\Models\User;
use App\Services\Parameters\SinAuthorizationService;
use App\Services\SinApiTokenService;
use App\Support\CompanyContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SiatCuisService
{
    public function __construct(
        private readonly SiatSoapClientFactory $clients,
        private readonly SinApiTokenService $apiTokens,
        private readonly SinAuthorizationService $authorizations,
    ) {}

    public function current(): ?SinCuis
    {
        return SinCuis::query()
            ->usable()
            ->latest('requested_at')
            ->first();
    }

    public function currentForPointOfSale(SinPointOfSale $pointOfSale): ?SinCuis
    {
        $cuis = SinCuis::query()
            ->usable()
            ->where(function ($query) use ($pointOfSale): void {
                $query
                    ->where('sin_point_of_sale_id', $pointOfSale->id)
                    ->orWhere(function ($query) use ($pointOfSale): void {
                        $query
                            ->whereNull('sin_point_of_sale_id')
                            ->where('company_id', $pointOfSale->company_id)
                            ->where('branch_code', $pointOfSale->branch->branch_code)
                            ->where('point_of_sale_code', $pointOfSale->point_of_sale_code);
                    });
            })
            ->latest('requested_at')
            ->first();

        if ($cuis && ! $cuis->sin_point_of_sale_id) {
            $cuis->update([
                'sin_branch_id' => $pointOfSale->sin_branch_id,
                'sin_point_of_sale_id' => $pointOfSale->id,
            ]);
        }

        return $cuis;
    }

    public function latestAttempt(): ?SinCuis
    {
        return SinCuis::query()
            ->latest('requested_at')
            ->first();
    }

    public function history(int $perPage = 15): mixed
    {
        return SinCuis::query()
            ->latest('requested_at')
            ->paginate($perPage);
    }

    public function request(User $user, SinPointOfSale $pointOfSale): SinCuis
    {
        $companyId = CompanyContext::id($user);

        if ($companyId === null || $companyId <= 0) {
            throw ValidationException::withMessages([
                'cuis' => 'Selecciona una empresa antes de solicitar CUIS.',
            ]);
        }

        $apiToken = $this->apiTokens->current();
        $authorization = $this->authorizations->current();

        $this->ensureReady($apiToken, $authorization, $pointOfSale);

        $startedAt = microtime(true);

        if (! extension_loaded('soap')) {
            return $this->storeAttempt(
                $companyId,
                $apiToken,
                $authorization,
                $pointOfSale,
                false,
                null,
                'La extension SOAP de PHP no esta disponible en el servidor.',
                null,
                $this->durationMs($startedAt),
            );
        }

        try {
            $client = $this->clients->make(SiatWsdlRegistry::CODES, (string) $apiToken->api_token);
            $response = $client->cuis($this->payload($authorization, $pointOfSale));
            $responseData = $this->normalizeResponse($response);
            $siatTransaction = $this->findTransaction($responseData) ?? false;
            $cuisCode = $this->findCuisCode($responseData, $siatTransaction);

            // Keep compatibility with older responses/mocks that expose the
            // generated value as `codigo`, without mistaking an error-message
            // code for a CUIS in an unsuccessful response.
            // SIAT can return an already-generated CUIS together with
            // transaccion=false. The returned code is still usable and must
            // be recovered when the local database does not contain it.
            $transaccion = $siatTransaction || $cuisCode !== null;

            return $this->storeAttempt(
                $companyId,
                $apiToken,
                $authorization,
                $pointOfSale,
                $transaccion,
                $cuisCode,
                $this->messageFor($siatTransaction, $responseData),
                $responseData,
                $this->durationMs($startedAt),
            );
        } catch (Throwable $exception) {
            return $this->storeAttempt(
                $companyId,
                $apiToken,
                $authorization,
                $pointOfSale,
                false,
                null,
                'No se pudo solicitar CUIS: '.Str::limit($exception->getMessage(), 280),
                null,
                $this->durationMs($startedAt),
            );
        }
    }

    public function importExisting(User $user, SinPointOfSale $pointOfSale, string $cuisCode): SinCuis
    {
        $companyId = CompanyContext::id($user);

        if ($companyId === null || $companyId <= 0) {
            throw ValidationException::withMessages([
                'cuis_code' => 'Selecciona una empresa antes de registrar un CUIS existente.',
            ]);
        }

        $apiToken = $this->apiTokens->current();
        $authorization = $this->authorizations->current();
        $this->ensureReady($apiToken, $authorization, $pointOfSale);

        return $this->storeAttempt(
            $companyId,
            $apiToken,
            $authorization,
            $pointOfSale,
            true,
            strtoupper(trim($cuisCode)),
            'CUIS existente importado durante la recuperación de la base de datos.',
            ['source' => 'manual_database_recovery'],
            0,
        );
    }

    private function ensureReady(?SinApiToken $apiToken, ?SinAuthorization $authorization, SinPointOfSale $pointOfSale): void
    {
        $messages = [];

        if (! $apiToken) {
            $messages['api_token'] = 'Registra primero el token API y la URL WSDL.';
        } elseif ($apiToken->status_label !== 'Vigente') {
            $messages['api_token'] = "El token API esta {$apiToken->status_label}. Actualiza su vigencia antes de solicitar CUIS.";
        }

        if (! $authorization) {
            $messages['authorization'] = 'Registra primero la autorizacion SIN con NIT, codigo de sistema y parametros SIAT.';
        }

        if (! $pointOfSale->is_active || ! $pointOfSale->branch?->is_active) {
            $messages['sin_point_of_sale_id'] = 'Selecciona una sucursal y punto de venta activos.';
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * @return array{SolicitudCuis: array<string, int|string>}
     */
    private function payload(SinAuthorization $authorization, SinPointOfSale $pointOfSale): array
    {
        return [
            'SolicitudCuis' => [
                'codigoAmbiente' => $authorization->environment_code->value,
                'codigoModalidad' => $authorization->modality_code->value,
                'codigoPuntoVenta' => $pointOfSale->point_of_sale_code,
                'codigoSistema' => (string) $authorization->system_code,
                'codigoSucursal' => $pointOfSale->branch->branch_code,
                'nit' => $authorization->tax_id,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    private function storeAttempt(
        int $companyId,
        SinApiToken $apiToken,
        SinAuthorization $authorization,
        SinPointOfSale $pointOfSale,
        bool $transaccion,
        ?string $cuisCode,
        string $message,
        ?array $response,
        int $durationMs,
    ): SinCuis {
        return SinCuis::query()->create([
            'company_id' => $companyId,
            'sin_api_token_id' => $apiToken->id,
            'sin_authorization_id' => $authorization->id,
            'sin_branch_id' => $pointOfSale->branch->id,
            'sin_point_of_sale_id' => $pointOfSale->id,
            'tax_id' => $authorization->tax_id,
            'wsdl_url' => SiatWsdlRegistry::CODES,
            'environment_code' => $authorization->environment_code,
            'modality_code' => $authorization->modality_code,
            'branch_code' => $pointOfSale->branch->branch_code,
            'point_of_sale_code' => $pointOfSale->point_of_sale_code,
            'transaccion' => $transaccion,
            'cuis_code' => $cuisCode,
            'message' => $message,
            'response' => $response,
            'duration_ms' => $durationMs,
            'requested_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeResponse(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        $json = json_encode($response);

        if (! is_string($json)) {
            return ['value' => $response];
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : ['value' => $response];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findTransaction(array $data): ?bool
    {
        foreach ($data as $key => $value) {
            if ($key === 'transaccion') {
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }

            if (is_array($value)) {
                $transaction = $this->findTransaction($value);

                if ($transaction !== null) {
                    return $transaction;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     */
    private function findValue(array $data, array $keys): ?string
    {
        $normalizedKeys = array_map('strtolower', $keys);

        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $normalizedKeys, true) && is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }

            if (is_array($value)) {
                $found = $this->findValue($value, $keys);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findCuisCode(array $data, bool $transaction): ?string
    {
        $code = $this->findValue($data, ['codigoCUIS', 'codigoCuis', 'cuis']);

        if ($code !== null) {
            return $code;
        }

        foreach ($data as $key => $value) {
            if (strcasecmp((string) $key, 'RespuestaCuis') === 0 && is_array($value)) {
                $directCode = $value['codigo'] ?? null;

                if (is_scalar($directCode) && preg_match('/[A-Za-z]/', (string) $directCode) === 1) {
                    return trim((string) $directCode);
                }
            }

            if (is_array($value)) {
                $nested = $this->findCuisCode($value, $transaction);

                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return $transaction ? $this->findValue($data, ['codigo']) : null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function messageFor(bool $transaccion, array $response): string
    {
        if ($transaccion) {
            return 'CUIS generado correctamente.';
        }

        $message = $this->findValue($response, ['descripcion', 'mensaje', 'descripcionMensaje']);

        return $message ?: 'SIAT no genero CUIS. Si ya existe un CUIS vigente para estos parametros, se mantiene el ultimo codigo generado.';
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
