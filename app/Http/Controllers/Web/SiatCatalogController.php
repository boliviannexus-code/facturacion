<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkUpdateSinCatalogItemsStatusRequest;
use App\Http\Requests\SyncSiatCatalogRequest;
use App\Http\Requests\UpdateSinCatalogItemStatusRequest;
use App\Models\SinCatalogItem;
use App\Services\Siat\SiatCatalogRegistry;
use App\Services\Siat\SiatCatalogSyncService;
use App\Services\Siat\SinBranchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SiatCatalogController extends Controller
{
    public function __construct(
        private readonly SiatCatalogRegistry $catalogs,
        private readonly SiatCatalogSyncService $syncs,
        private readonly SinBranchService $branches,
    ) {}

    public function index(): View
    {
        return view('siat.catalogs.index', [
            'catalogs' => $this->syncs->catalogSummaries(),
            'pointOptions' => $this->branches->activePointOptions(),
        ]);
    }

    public function show(string $catalog): View
    {
        return view('siat.catalogs.show', [
            'catalog' => $this->catalogs->find($catalog),
            'pointOptions' => $this->branches->activePointOptions(),
        ]);
    }

    public function sync(SyncSiatCatalogRequest $request, string $catalog): RedirectResponse
    {
        $syncs = $this->syncs->syncMany(
            $request->user(),
            $catalog,
            $request->pointOfSale(),
            $request->syncCount()
        );
        $successfulCount = $syncs->filter(fn ($sync): bool => (bool) $sync->transaccion)->count();
        $lastSync = $syncs->last();
        $totalCount = $syncs->count();
        $message = 'Sincronizacion ejecutada '
            .$totalCount.' '
            .($totalCount === 1 ? 'vez' : 'veces')
            .". Exitosas: {$successfulCount}. Observadas: "
            .($totalCount - $successfulCount).'.';

        return redirect()
            ->route('siat.catalogs.show', $catalog)
            ->with(
                $successfulCount === $totalCount ? 'success' : 'warning',
                $lastSync ? "{$message} Ultimo resultado: {$lastSync->message}" : $message
            );
    }

    public function syncAll(SyncSiatCatalogRequest $request): RedirectResponse
    {
        $syncs = $this->syncs->syncAll($request->user(), $request->pointOfSale());
        $successfulCount = $syncs->filter(fn ($sync): bool => (bool) $sync->transaccion)->count();
        $totalCount = $syncs->count();
        $observedCount = $totalCount - $successfulCount;
        $message = "Sincronizacion general finalizada. Catalogos: {$totalCount}. "
            ."Exitosos: {$successfulCount}. Observados: {$observedCount}.";

        return redirect()
            ->route('siat.catalogs.index')
            ->with($observedCount === 0 ? 'success' : 'warning', $message);
    }

    public function updateItemStatus(UpdateSinCatalogItemStatusRequest $request, string $catalog, SinCatalogItem $item): RedirectResponse
    {
        $this->syncs->setItemStatus($request->user(), $catalog, $item, $request->isActive());

        return redirect()
            ->route('siat.catalogs.show', $catalog)
            ->with('success', 'Estado del item actualizado correctamente.');
    }

    public function updateItemsStatus(BulkUpdateSinCatalogItemsStatusRequest $request, string $catalog): RedirectResponse
    {
        $affected = $this->syncs->setItemsStatus($request->user(), $catalog, $request->itemIds(), $request->isActive());

        return redirect()
            ->route('siat.catalogs.show', $catalog)
            ->with('success', "Items actualizados: {$affected}.");
    }
}
