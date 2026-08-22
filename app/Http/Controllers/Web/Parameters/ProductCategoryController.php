<?php

namespace App\Http\Controllers\Web\Parameters;

use App\Http\Controllers\Controller;
use App\Http\Requests\Parameters\StoreProductCategoryRequest;
use App\Http\Requests\Parameters\UpdateProductCategoryRequest;
use App\Models\ProductCategory;
use App\Services\Parameters\ProductCategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProductCategoryController extends Controller
{
    public function __construct(
        private readonly ProductCategoryService $categories,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', ProductCategory::class);

        return view('parameters.product-categories.index', [
            'categories' => $this->categories->paginate(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', ProductCategory::class);

        return view('parameters.product-categories.create');
    }

    public function store(StoreProductCategoryRequest $request): RedirectResponse
    {
        $this->categories->create($request->user(), $request->validated());

        return redirect()
            ->route('parameters.categories.index')
            ->with('success', 'Categoria registrada correctamente.');
    }

    public function edit(ProductCategory $productCategory): View
    {
        $this->authorize('update', $productCategory);

        return view('parameters.product-categories.edit', [
            'category' => $productCategory,
        ]);
    }

    public function update(
        UpdateProductCategoryRequest $request,
        ProductCategory $productCategory
    ): RedirectResponse {
        $this->authorize('update', $productCategory);

        $this->categories->update($productCategory, $request->validated());

        return redirect()
            ->route('parameters.categories.index')
            ->with('success', 'Categoria actualizada correctamente.');
    }

    public function destroy(ProductCategory $productCategory): RedirectResponse
    {
        $this->authorize('delete', $productCategory);

        $this->categories->delete($productCategory);

        return redirect()
            ->route('parameters.categories.index')
            ->with('success', 'Categoria eliminada correctamente.');
    }
}
