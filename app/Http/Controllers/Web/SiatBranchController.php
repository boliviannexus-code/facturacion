<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSinBranchRequest;
use App\Http\Requests\StoreSinPointOfSaleRequest;
use App\Models\SinBranch;
use App\Services\Siat\SiatPointOfSaleService;
use App\Services\Siat\SinBranchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SiatBranchController extends Controller
{
    public function __construct(
        private readonly SinBranchService $branches,
        private readonly SiatPointOfSaleService $pointsOfSale,
    ) {}

    public function index(): View
    {
        return view('siat.branches.index', [
            'branches' => $this->branches->branches(),
            'pointOfSaleTypes' => SiatPointOfSaleService::TYPES,
        ]);
    }

    public function store(StoreSinBranchRequest $request): RedirectResponse
    {
        $this->branches->createBranch($request->user(), $request->validated());

        return redirect()
            ->route('siat.branches.index')
            ->with('success', 'Sucursal registrada correctamente.');
    }

    public function storePoint(StoreSinPointOfSaleRequest $request, SinBranch $branch): RedirectResponse
    {
        $point = $this->pointsOfSale->register($branch, $request->validated());

        return redirect()
            ->route('siat.branches.index')
            ->with('success', "Punto de venta {$point->point_of_sale_code} registrado correctamente en el SIN.");
    }

    public function synchronizePoints(SinBranch $branch): RedirectResponse
    {
        $result = $this->pointsOfSale->synchronize($branch);

        return redirect()
            ->route('siat.branches.index')
            ->with(
                'success',
                "Recuperación desde Impuestos completada: {$result->received} recibido(s), {$result->created} nuevo(s) y {$result->updated} actualizado(s).",
            );
    }
}
