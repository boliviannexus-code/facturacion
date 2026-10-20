<?php

namespace App\Http\Controllers\Web\Parameters;

use App\Http\Controllers\Controller;
use App\Http\Requests\Parameters\StoreProductRequest;
use App\Http\Requests\Parameters\UpdateProductRequest;
use App\Models\Product;
use App\Services\Parameters\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', Product::class);

        return view('parameters.products.index', [
            'products' => $this->products->paginate(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Product::class);

        return view('parameters.products.create', $this->products->formOptions());
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $this->products->create($request->user(), $request->validated());

        return redirect()
            ->route('parameters.products.index')
            ->with('success', 'Producto registrado correctamente.');
    }

    public function edit(Product $product): View
    {
        $this->authorize('update', $product);

        return view('parameters.products.edit', [
            'product' => $product,
            ...$this->products->formOptions($product),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $this->products->update($product, $request->validated());

        return redirect()
            ->route('parameters.products.index')
            ->with('success', 'Producto actualizado correctamente.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        $this->products->delete($product);

        return redirect()
            ->route('parameters.products.index')
            ->with('success', 'Producto eliminado correctamente.');
    }
}
