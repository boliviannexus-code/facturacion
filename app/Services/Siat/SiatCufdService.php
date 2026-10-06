<?php

namespace App\Services\Siat;

use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinCufd;
use App\Models\SinCuis;
use App\Models\SinPointOfSale;
use App\Models\User;
use App\Services\Parameters\SinAuthorizationService;
use App\Services\SinApiTokenService;
use App\Support\CompanyContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SiatCufdService
{
    public function __construct(
        private readonly SiatSoapClientFactory $clients,
        private readonly SinApiTokenService $apiTokens,
        private readonly SinAuthorizationService $authorizations,
        private readonly SiatCuisService $cuis,
    ) {}

    public function currentForPointOfSale(SinPointOfSale $pointOfSale): ?SinCufd
    {
        return SinCufd::query()
            ->current()
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
    }

    public function request(User $user, SinPointOfSale $pointOfSale): SinCufd
    {
        $apiToken = $this->apiTokens->current();
        $authorization = $this->authorizations->current();
        $currentCuis = $this->cuis->currentForPointOfSale($pointOfSale);

        if (! $apiToken || ! $authorization || ! $currentCuis) {
            $this->ensureReady($apiToken, $authorization, $pointOfSale, $currentCuis);
        }

        return $this->requestWithConfiguration(
            $user,
            $pointOfSale,
            $apiToken,
            $authorization,
            $currentCuis,
        );
    }

    public function requestWithConfiguration(
        User $user,
        SinPointOfSale $pointOfSale,
        SinApiToken $apiToken,
        SinAuthorization $authorization,
        SinCuis $currentCuis,
    ): SinCufd {
        $companyId = CompanyContext::id($user);

        if ($companyId === null || $companyId <= 0) {
            throw ValidationException::withMessages([
                'cufd' => 'Selecciona una empresa antes de solicitar CUFD.',
            ]);
        }

        foreach ([$pointOfSale, $apiToken, $authorization, $currentCuis] as $configuration) {
            if ((int) $configuration->company_id !== $companyId) {
                throw ValidationException::withMessages([
                    'cufd' => 'La configuracion usada para solicitar CUFD pertenece a otra empresa.',
                ]);
            }
        }

        $this->ensureReady($apiToken, $authorization, $pointOfSale, $currentCuis);

        $startedAt = microtime(true);

        if (! extension_loaded('soap')) {
            return $this->storeAttempt(
                $companyId,
                $apiToken,
                $authorization,
                $pointOfSale,
                $currentCuis,
                false,
                null,
                null,
                null,
                null,
                'La extension SOAP de PHP no esta disponible en el servidor.',
                null,
                $this->durationMs($startedAt),
            );
        }

        try {
            $client = $this->clients->make(SiatWsdlRegistry::CODES, (string) $apiToken->api_token);
            $response = $client->cufd($this->payload($authorization, $pointOfSale, $currentCuis));
            $responseData = $this->normalizeResponse($response);
            $transaccion = $this->findTransaction($responseData) ?? false;
            $cufdCode = $transaccion ? $this->findValue($responseData, ['codigo', 'codigoCufd', 'cufd']) : null;
            $controlCode = $transaccion ? $this->findValue($responseData, ['codigoControl']) : null;
            $address = $transaccion ? $this->findValue($responseData, ['direccion']) : null;
            $expiresAt = $transaccion ? $this->expiresAt($this->findValue($responseData, ['fechaVigencia'])) : null;

            return $this->storeAttempt(
                $companyId,
                $apiToken,
                $authorization,
                $pointOfSale,
                $currentCuis,
                $transaccion,
                $cufdCode,
                $controlCode,
                $address,
                $expiresAt,
                $this->messageFor($transaccion, $responseData),
                $responseData,
                $this->durationMs($startedAt),
            );
        } catch (Throwable $exception) {
            return $this->storeAttempt(
                $companyId,
                $apiToken,
                $authorization,
                $pointOfSale,
                $currentCuis,
                false,
                null,
                null,
                null,
                null,
                'No se pudo solicitar CUFD: '.Str::limit($exception->getMessage(), 280),
                null,
                $this->durationMs($startedAt),
            );
        }
    }

    private function ensureReady(
        ?SinApiToken $apiToken,
        ?SinAuthorization $authorization,
        SinPointOfSale $pointOfSale,
        ?SinCuis $cuis,
    ): void {
        $messages = [];

        if (! $apiToken) {
            $messages['api_token'] = 'Registra primero el token API y la URL WSDL.';
        } elseif ($apiToken->status_label !== 'Vigente') {
            $messages['api_token'] = "El token API esta {$apiToken->status_label}. Actualiza su vigencia antes de solicitar CUFD.";
        }

        if (! $authorization) {
            $messages['authorization'] = 'Registra primero la autorizacion SIN con NIT, codigo de sistema y parametros SIAT.';
        }

        if (! $pointOfSale->is_active || ! $pointOfSale->branch?->is_active) {
            $messages['sin_point_of_sale_id'] = 'Selecciona una sucursal y punto de venta activos.';
        }

        if (! $cuis) {
            $messages['cuis'] = 'Genera primero el CUIS vigente para esta sucursal y punto de venta.';
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * @return array{SolicitudCufd: array<string, int|string>}
     */
    private function payload(SinAuthorization $authorization, SinPointOfSale $pointOfSale, SinCuis $cuis): array
    {
        return [
            'SolicitudCufd' => [
                'codigoAmbiente' => $authorization->environment_code->value,
                'codigoModalidad' => $authorization->modality_code->value,
                'codigoPuntoVenta' => $pointOfSale->point_of_sale_code,
                'codigoSistema' => (string) $authorization->system_code,
                'codigoSucursal' => $pointOfSale->branch->branch_code,
                'cuis' => (string) $cuis->cuis_code,
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
        SinCuis $cuis,
        bool $transaccion,
        ?string $cufdCode,
        ?string $controlCode,
        ?string $address,
        ?Carbon $expiresAt,
        string $message,
        ?array $response,
        int $durationMs,
    ): SinCufd {
        return DB::transaction(function () use (
            $companyId,
            $apiToken,
            $authorization,
            $pointOfSale,
            $cuis,
            $transaccion,
            $cufdCode,
            $controlCode,
            $address,
            $expiresAt,
            $message,
            $response,
            $durationMs,
        ): SinCufd {
            if ($transaccion && filled($cufdCode)) {
                SinCufd::query()
                    ->withoutGlobalScope('company')
                    ->where('company_id', $companyId)
                    ->where('sin_point_of_sale_id', $pointOfSale->id)
                    ->whereNull('invalidated_at')
                    ->successful()
                    ->update(['invalidated_at' => now()]);
            }

            return SinCufd::query()->create([
                'company_id' => $companyId,
                'sin_api_token_id' => $apiToken->id,
                'sin_authorization_id' => $authorization->id,
                'sin_branch_id' => $pointOfSale->branch->id,
                'sin_point_of_sale_id' => $pointOfSale->id,
                'sin_cuis_id' => $cuis->id,
                'tax_id' => $authorization->tax_id,
                'wsdl_url' => SiatWsdlRegistry::CODES,
                'environment_code' => $authorization->environment_code,
                'modality_code' => $authorization->modality_code,
                'branch_code' => $pointOfSale->branch->branch_code,
                'point_of_sale_code' => $pointOfSale->point_of_sale_code,
                'transaccion' => $transaccion,
                'cufd_code' => $cufdCode,
                'control_code' => $controlCode,
                'address' => $address,
                'expires_at' => $expiresAt,
                'message' => $message,
                'response' => $response,
                'duration_ms' => $durationMs,
                'requested_at' => now(),
            ]);
        }, 3);
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
        foreach ($data as $key => $value) {
            if (in_array($key, $keys, true) && is_scalar($value) && trim((string) $value) !== '') {
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
     * @param  array<string, mixed>  $response
     */
    private function messageFor(bool $transaccion, array $response): string
    {
        if ($transaccion) {
            return 'CUFD generado correctamente.';
        }

        $message = $this->findValue($response, ['descripcion', 'mensaje', 'descripcionMensaje']);

        return $message ?: 'SIAT no genero CUFD.';
    }

    private function expiresAt(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
