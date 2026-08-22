<?php

namespace App\Services\Siat;

use App\Models\SinApiToken;
use App\Models\SinAuthorization;
use App\Models\SinCatalogItem;
use App\Models\SinCatalogSync;
use App\Models\SinCuis;
use App\Models\SinPointOfSale;
use App\Models\User;
use App\Services\Parameters\SinAuthorizationService;
use App\Services\SinApiTokenService;
use App\Support\CompanyContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SiatCatalogSyncService
{
    public function __construct(
        private readonly SiatSoapClientFactory $clients,
        private readonly SiatCatalogRegistry $catalogs,
        private readonly SinApiTokenService $apiTokens,
        private readonly SinAuthorizationService $authorizations,
        private readonly SiatCuisService $cuis,
    ) {}

    public function catalogSummaries(): Collection
    {
        $lastSyncs = SinCatalogSync::query()
            ->latest('synced_at')
            ->get()
            ->unique('catalog_key')
            ->keyBy('catalog_key');

        $itemCounts = SinCatalogItem::query()
            ->selectRaw('catalog_key, count(*) as aggregate')
            ->groupBy('catalog_key')
            ->pluck('aggregate', 'catalog_key');

        return $this->catalogs->all()->map(function (array $catalog) use ($lastSyncs, $itemCounts): array {
            return [
                ...$catalog,
                'items_count' => (int) ($itemCounts[$catalog['key']] ?? 0),
                'last_sync' => $lastSyncs->get($catalog['key']),
            ];
        });
    }

    public function items(string $catalogKey, int $perPage = 25): LengthAwarePaginator
    {
        return SinCatalogItem::query()
            ->where('catalog_key', $catalogKey)
            ->orderByRaw('classifier_code is null')
            ->orderBy('classifier_code')
            ->orderBy('description')
            ->paginate($perPage);
    }

    public function sync(User $user, string $catalogKey, SinPointOfSale $pointOfSale): SinCatalogSync
    {
        $companyId = CompanyContext::id($user);

        if ($companyId === null || $companyId <= 0) {
            throw ValidationException::withMessages([
                'catalog' => 'Selecciona una empresa antes de sincronizar catalogos SIAT.',
            ]);
        }

        $catalog = $this->catalogs->find($catalogKey);
        $apiToken = $this->apiTokens->current();
        $authorization = $this->authorizations->current();
        $cuis = $this->cuis->currentForPointOfSale($pointOfSale);

        $this->ensureReady($apiToken, $authorization, $cuis, $pointOfSale);

        $startedAt = microtime(true);

        if (! extension_loaded('soap')) {
            return $this->storeSync(
                $companyId,
                $catalog,
                $apiToken,
                $authorization,
                $cuis,
                false,
                [],
                'La extension SOAP de PHP no esta disponible en el servidor.',
                null,
                $this->durationMs($startedAt),
            );
        }

        try {
            $client = $this->clients->make($catalog['wsdl_url'], (string) $apiToken->api_token);
            $operation = $catalog['operation'];
            $response = $client->{$operation}($this->payload($authorization, $cuis));
            $responseData = $this->normalizeResponse($response);
            $transaccion = $this->findTransaction($responseData) ?? false;
            $items = $transaccion
                ? $this->uniqueItems($this->extractItems($responseData), $catalog['key'])
                : [];

            return $this->storeSync(
                $companyId,
                $catalog,
                $apiToken,
                $authorization,
                $cuis,
                $transaccion,
                $items,
                $this->messageFor($transaccion, count($items), $responseData),
                $responseData,
                $this->durationMs($startedAt),
            );
        } catch (Throwable $exception) {
            return $this->storeSync(
                $companyId,
                $catalog,
                $apiToken,
                $authorization,
                $cuis,
                false,
                [],
                'No se pudo sincronizar catalogo SIAT: '.Str::limit($exception->getMessage(), 280),
                null,
                $this->durationMs($startedAt),
            );
        }
    }

    /**
     * @return Collection<int, SinCatalogSync>
     */
    public function syncMany(User $user, string $catalogKey, SinPointOfSale $pointOfSale, int $times): Collection
    {
        return collect(range(1, max(1, $times)))
            ->map(fn (): SinCatalogSync => $this->sync($user, $catalogKey, $pointOfSale));
    }

    /**
     * @return Collection<int, SinCatalogSync>
     */
    public function syncAll(User $user, SinPointOfSale $pointOfSale): Collection
    {
        return $this->catalogs->all()
            ->map(fn (array $catalog): SinCatalogSync => $this->sync($user, $catalog['key'], $pointOfSale))
            ->values();
    }

    public function setItemStatus(User $user, string $catalogKey, SinCatalogItem $item, bool $isActive): SinCatalogItem
    {
        $companyId = CompanyContext::id($user);

        abort_unless($companyId !== null && $companyId > 0, 403);
        abort_unless((int) $item->company_id === $companyId && $item->catalog_key === $catalogKey, 404);

        $item->update(['is_active' => $isActive]);

        return $item->refresh();
    }

    /**
     * @param  array<int, int>  $itemIds
     */
    public function setItemsStatus(User $user, string $catalogKey, array $itemIds, bool $isActive): int
    {
        $companyId = CompanyContext::id($user);

        abort_unless($companyId !== null && $companyId > 0, 403);

        return SinCatalogItem::query()
            ->where('company_id', $companyId)
            ->where('catalog_key', $catalogKey)
            ->when($itemIds !== [], fn ($query) => $query->whereIn('id', $itemIds))
            ->update(['is_active' => $isActive]);
    }

    private function ensureReady(?SinApiToken $apiToken, ?SinAuthorization $authorization, ?SinCuis $cuis, SinPointOfSale $pointOfSale): void
    {
        $messages = [];

        if (! $apiToken) {
            $messages['api_token'] = 'Registra primero el token API.';
        } elseif ($apiToken->status_label !== 'Vigente') {
            $messages['api_token'] = "El token API esta {$apiToken->status_label}. Actualiza su vigencia antes de sincronizar catalogos.";
        }

        if (! $authorization) {
            $messages['authorization'] = 'Registra primero la autorizacion SIN.';
        }

        if (! $cuis) {
            $messages['cuis'] = 'Genera primero el CUIS para la sucursal y punto de venta seleccionados.';
        }

        if (! $pointOfSale->is_active || ! $pointOfSale->branch?->is_active) {
            $messages['sin_point_of_sale_id'] = 'Selecciona una sucursal y punto de venta activos.';
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * @return array{SolicitudSincronizacion: array<string, int|string>}
     */
    private function payload(SinAuthorization $authorization, SinCuis $cuis): array
    {
        return [
            'SolicitudSincronizacion' => [
                'codigoAmbiente' => $authorization->environment_code->value,
                'codigoPuntoVenta' => $cuis->point_of_sale_code,
                'codigoSistema' => (string) $authorization->system_code,
                'codigoSucursal' => $cuis->branch_code,
                'cuis' => (string) $cuis->cuis_code,
                'nit' => $authorization->tax_id,
            ],
        ];
    }

    /**
     * @param  array{key: string, name: string, operation: string, wsdl_url: string}  $catalog
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $response
     */
    private function storeSync(
        int $companyId,
        array $catalog,
        SinApiToken $apiToken,
        SinAuthorization $authorization,
        SinCuis $cuis,
        bool $transaccion,
        array $items,
        string $message,
        ?array $response,
        int $durationMs,
    ): SinCatalogSync {
        return DB::transaction(function () use ($companyId, $catalog, $apiToken, $authorization, $cuis, $transaccion, $items, $message, $response, $durationMs): SinCatalogSync {
            $syncedAt = now();
            $sync = SinCatalogSync::query()->create([
                'company_id' => $companyId,
                'sin_api_token_id' => $apiToken->id,
                'sin_authorization_id' => $authorization->id,
                'sin_cuis_id' => $cuis->id,
                'catalog_key' => $catalog['key'],
                'catalog_name' => $catalog['name'],
                'operation' => $catalog['operation'],
                'wsdl_url' => $catalog['wsdl_url'],
                'transaccion' => $transaccion,
                'items_count' => count($items),
                'message' => $message,
                'response' => $response,
                'duration_ms' => $durationMs,
                'synced_at' => $syncedAt,
            ]);

            if ($transaccion) {
                $activeStates = SinCatalogItem::query()
                    ->where('company_id', $companyId)
                    ->where('catalog_key', $catalog['key'])
                    ->pluck('is_active', 'item_key')
                    ->all();
                $catalogItems = $this->catalogItems($companyId, $catalog['key'], $items, $syncedAt);

                SinCatalogItem::query()
                    ->where('company_id', $companyId)
                    ->where('catalog_key', $catalog['key'])
                    ->delete();

                foreach ($catalogItems as $item) {
                    $item['is_active'] = (bool) ($activeStates[$item['item_key']] ?? true);

                    SinCatalogItem::query()->create($item);
                }
            }

            return $sync;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function catalogItems(int $companyId, string $catalogKey, array $items, Carbon $syncedAt): array
    {
        return collect($items)
            ->map(fn (array $item): array => [
                'company_id' => $companyId,
                'catalog_key' => $catalogKey,
                'item_key' => $this->itemKey($item, $catalogKey),
                'classifier_code' => $this->classifierCode($item),
                'description' => $this->description($item),
                'raw_data' => $item,
                'synced_at' => $syncedAt,
            ])
            ->unique('item_key')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeResponse(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        if (is_string($response) && str_starts_with(trim($response), '<')) {
            $xmlData = $this->normalizeXmlResponse($response);

            if ($xmlData !== []) {
                return $xmlData;
            }
        }

        $json = json_encode($response);

        if (! is_string($json)) {
            return ['value' => $response];
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : ['value' => $response];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeXmlResponse(string $response): array
    {
        $document = new \DOMDocument;

        if (! @$document->loadXML($response, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return [];
        }

        $root = $document->documentElement;

        $data = $root instanceof \DOMElement ? $this->xmlElementToArray($root) : [];

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>|string|null
     */
    private function xmlElementToArray(\DOMElement $element): array|string|null
    {
        $children = collect(iterator_to_array($element->childNodes))
            ->filter(fn (\DOMNode $node): bool => $node instanceof \DOMElement)
            ->values();

        if ($children->isEmpty()) {
            $text = trim($element->textContent);

            return $text === '' ? null : $text;
        }

        $data = [];

        foreach ($children->groupBy(fn (\DOMElement $child): string => $child->localName) as $name => $group) {
            $values = $group
                ->map(fn (\DOMElement $child): mixed => $this->xmlElementToArray($child))
                ->all();

            $data[$name] = count($values) === 1 ? $values[0] : $values;
        }

        return $data;
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
     * @return array<int, array<string, mixed>>
     */
    private function extractItems(array $data): array
    {
        foreach ($data as $key => $value) {
            if (str_starts_with((string) $key, 'lista') && is_array($value)) {
                return $this->listItems($value);
            }

            if ($key === 'fechaHora' && is_scalar($value) && trim((string) $value) !== '') {
                return [['fechaHora' => trim((string) $value)]];
            }

            if (is_array($value)) {
                $items = $this->extractItems($value);

                if ($items !== []) {
                    return $items;
                }
            }
        }

        return [];
    }

    /**
     * @param  array<mixed>  $value
     * @return array<int, array<string, mixed>>
     */
    private function listItems(array $value): array
    {
        if (array_is_list($value)) {
            return array_values(array_filter($value, fn (mixed $item): bool => is_array($item)));
        }

        return [$value];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function uniqueItems(array $items, string $catalogKey): array
    {
        return collect($items)
            ->unique(fn (array $item): string => $this->itemKey($item, $catalogKey))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemKey(array $item, string $catalogKey): string
    {
        if (array_key_exists('fechaHora', $item)) {
            return 'fechaHora';
        }

        $parts = [];

        foreach ($this->codeKeys() as $key) {
            if (array_key_exists($key, $item) && is_scalar($item[$key]) && trim((string) $item[$key]) !== '') {
                $parts[$key] = trim((string) $item[$key]);
            }
        }

        if ($catalogKey === 'leyendas_factura') {
            $legend = $this->firstScalar($item, ['descripcionLeyenda', 'descripcion']);

            if ($legend !== null) {
                $parts['leyenda'] = sha1(mb_strtolower(Str::squish($legend)));
            }
        }

        if ($parts !== []) {
            $key = collect($parts)
                ->map(fn (string $value, string $key): string => "{$key}:{$value}")
                ->implode('|');

            return Str::length($key) <= 160 ? $key : sha1($key);
        }

        return sha1(json_encode($item) ?: serialize($item));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function classifierCode(array $item): ?string
    {
        return $this->firstScalar($item, $this->codeKeys());
    }

    /**
     * @return array<int, string>
     */
    private function codeKeys(): array
    {
        return [
            'codigoClasificador',
            'codigoActividad',
            'codigoProducto',
            'codigoProductoServicio',
            'codigoMensaje',
            'codigoDocumentoSector',
            'codigoTipoFactura',
            'codigo',
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function description(array $item): ?string
    {
        return $this->firstScalar($item, [
            'descripcion',
            'descripcionLeyenda',
            'productoServicio',
            'descripcionProducto',
            'fechaHora',
            'mensaje',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $keys
     */
    private function firstScalar(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function messageFor(bool $transaccion, int $itemsCount, array $response): string
    {
        if ($transaccion) {
            return "Catalogo sincronizado correctamente. Registros: {$itemsCount}.";
        }

        return $this->findMessage($response) ?: 'SIAT no sincronizo el catalogo. Se conservan los datos anteriores.';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function findMessage(array $data): ?string
    {
        foreach ($data as $key => $value) {
            if (in_array($key, ['descripcion', 'mensaje', 'descripcionMensaje'], true) && is_scalar($value)) {
                return trim((string) $value);
            }

            if (is_array($value)) {
                $message = $this->findMessage($value);

                if ($message !== null) {
                    return $message;
                }
            }
        }

        return null;
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
