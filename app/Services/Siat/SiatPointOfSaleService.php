<?php

declare(strict_types=1);

namespace App\Services\Siat;

use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinBranch;
use App\Models\SinCuis;
use App\Models\SinPointOfSale;
use App\Services\Parameters\SinAuthorizationService;
use App\Services\SinApiTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SiatPointOfSaleService
{
    /** @var array<int, string> */
    public const TYPES = [
        1 => 'Punto de Venta Comisionista',
        2 => 'Ventanilla de Cobranza',
        3 => 'Punto de Venta Móvil',
        4 => 'Punto de Venta YPFB',
        5 => 'Punto de Venta Cajero',
        6 => 'Punto de Venta Conjunta',
    ];

    public function __construct(
        private readonly SiatSoapClientFactory $clients,
        private readonly SinApiTokenService $apiTokens,
        private readonly SinAuthorizationService $authorizations,
    ) {}

    /**
     * Registra el punto en SIAT. El código nunca se genera localmente: es el
     * valor retornado por registroPuntoVenta.
     *
     * @param  array{point_of_sale_type_code: int|string, name: string, description: string}  $data
     */
    public function register(SinBranch $branch, array $data): SinPointOfSale
    {
        [$token, $authorization, $cuis] = $this->credentials($branch);

        try {
            $response = $this->clients
                ->make($this->operationsWsdl($token), (string) $token->api_token)
                ->registroPuntoVenta([
                    'SolicitudRegistroPuntoVenta' => [
                        'codigoAmbiente' => $authorization->environment_code->value,
                        'codigoModalidad' => $authorization->modality_code->value,
                        'codigoSistema' => (string) $authorization->system_code,
                        'codigoSucursal' => $branch->branch_code,
                        'codigoTipoPuntoVenta' => (int) $data['point_of_sale_type_code'],
                        'cuis' => (string) $cuis->cuis_code,
                        'descripcion' => trim($data['description']),
                        'nit' => (int) $authorization->tax_id,
                        'nombrePuntoVenta' => trim($data['name']),
                    ],
                ]);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'siat' => 'No se pudo conectar con registroPuntoVenta del SIN: '.$exception->getMessage(),
            ]);
        }

        $responseData = $this->normalize($response);
        $transaction = $this->findBoolean($responseData, 'transaccion');
        $code = $this->findScalar($responseData, 'codigoPuntoVenta');

        if ($transaction !== true || $code === null || (int) $code <= 0) {
            throw ValidationException::withMessages([
                'siat' => $this->responseMessage($responseData, 'El SIN rechazó el registro del punto de venta.'),
            ]);
        }

        return SinPointOfSale::query()->updateOrCreate(
            [
                'company_id' => $branch->company_id,
                'sin_branch_id' => $branch->id,
                'point_of_sale_code' => (int) $code,
            ],
            [
                'point_of_sale_type_code' => (int) $data['point_of_sale_type_code'],
                'point_of_sale_type' => self::TYPES[(int) $data['point_of_sale_type_code']],
                'name' => trim($data['name']),
                'description' => trim($data['description']),
                'is_default' => false,
                'is_active' => true,
                'registered_at' => now(),
                'last_synced_at' => now(),
            ],
        );
    }

    /**
     * Consulta SIAT y refleja localmente la lista oficial de la sucursal.
     */
    public function synchronize(SinBranch $branch): PointOfSaleSyncResult
    {
        [$token, $authorization, $cuis] = $this->credentials($branch);

        try {
            $response = $this->clients
                ->make($this->operationsWsdl($token), (string) $token->api_token)
                ->consultaPuntoVenta([
                    'SolicitudConsultaPuntoVenta' => [
                        'codigoAmbiente' => $authorization->environment_code->value,
                        'codigoSistema' => (string) $authorization->system_code,
                        'codigoSucursal' => $branch->branch_code,
                        'cuis' => (string) $cuis->cuis_code,
                        'nit' => (int) $authorization->tax_id,
                    ],
                ]);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'siat' => 'No se pudo conectar con consultaPuntoVenta del SIN: '.$exception->getMessage(),
            ]);
        }

        $responseData = $this->normalize($response);

        if ($this->findBoolean($responseData, 'transaccion') !== true) {
            throw ValidationException::withMessages([
                'siat' => $this->responseMessage($responseData, 'El SIN no pudo consultar los puntos de venta.'),
            ]);
        }

        $points = $this->pointList($responseData);

        [$created, $updated] = DB::transaction(function () use ($branch, $points): array {
            $created = 0;
            $updated = 0;

            foreach ($points as $point) {
                $code = (int) ($point['codigoPuntoVenta'] ?? 0);

                if ($code <= 0) {
                    continue;
                }

                $type = trim((string) ($point['tipoPuntoVenta'] ?? ''));
                $typeCode = array_search($type, self::TYPES, true);
                $description = trim((string) ($point['descripcion'] ?? $point['descripcionPuntoVenta'] ?? ''));
                $localPoint = SinPointOfSale::query()->firstOrNew(
                    [
                        'company_id' => $branch->company_id,
                        'sin_branch_id' => $branch->id,
                        'point_of_sale_code' => $code,
                    ],
                );

                $wasRecentlyDiscovered = ! $localPoint->exists;
                $localPoint->fill([
                    'point_of_sale_type_code' => $typeCode !== false ? $typeCode : $localPoint->point_of_sale_type_code,
                    'point_of_sale_type' => $type !== '' ? $type : $localPoint->point_of_sale_type,
                    'name' => trim((string) ($point['nombrePuntoVenta'] ?? "Punto de venta {$code}")),
                    'description' => $description !== '' ? $description : $localPoint->description,
                    'is_default' => false,
                    'is_active' => true,
                    'registered_at' => $localPoint->registered_at ?? now(),
                    'last_synced_at' => now(),
                ])->save();

                $wasRecentlyDiscovered ? $created++ : $updated++;
            }

            return [$created, $updated];
        });

        return new PointOfSaleSyncResult(count($points), $created, $updated);
    }

    /**
     * @return array{SinApiToken, SinAuthorization, SinCuis}
     */
    private function credentials(SinBranch $branch): array
    {
        $token = $this->apiTokens->current();
        $authorization = $this->authorizations->current();
        $messages = [];

        if (! $token || $token->status_label !== 'Vigente') {
            $messages['api_token'] = 'Se requiere un Token Delegado vigente para consumir FacturacionOperaciones.';
        }

        if (! $authorization || blank($authorization->system_code)) {
            $messages['authorization'] = 'Configura la autorización SIN y el código de sistema antes de continuar.';
        }

        $cuis = SinCuis::query()
            ->usable()
            ->where('sin_branch_id', $branch->id)
            ->where('point_of_sale_code', 0)
            ->latest('requested_at')
            ->first();

        if (! $cuis) {
            $messages['cuis'] = 'Genera primero un CUIS para el punto de venta 0 de esta sucursal.';
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }

        return [$token, $authorization, $cuis];
    }

    private function operationsWsdl(SinApiToken $token): string
    {
        return SiatWsdlRegistry::relatedService((string) $token->wsdl_url, 'FacturacionOperaciones');
    }

    /** @return array<string, mixed> */
    private function normalize(mixed $response): array
    {
        $decoded = json_decode((string) json_encode($response), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $data */
    private function findBoolean(array $data, string $key): ?bool
    {
        $value = $this->findScalar($data, $key);

        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /** @param array<string, mixed> $data */
    private function findScalar(array $data, string $key): string|int|bool|null
    {
        foreach ($data as $currentKey => $value) {
            if (strcasecmp((string) $currentKey, $key) === 0 && is_scalar($value)) {
                return $value;
            }

            if (is_array($value) && ($found = $this->findScalar($value, $key)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function pointList(array $data): array
    {
        foreach ($data as $key => $value) {
            if (strcasecmp((string) $key, 'listaPuntosVentas') === 0) {
                if (! is_array($value)) {
                    return [];
                }

                return array_is_list($value) ? $value : [$value];
            }

            if (is_array($value) && ($points = $this->pointList($value)) !== []) {
                return $points;
            }
        }

        return [];
    }

    /** @param array<string, mixed> $data */
    private function responseMessage(array $data, string $fallback): string
    {
        $description = $this->findScalar($data, 'descripcion');

        return is_scalar($description) && trim((string) $description) !== ''
            ? trim((string) $description)
            : $fallback;
    }
}
