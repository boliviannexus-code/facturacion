@extends('layouts.admin')

@section('title', 'Productos | '.config('app.name', 'Base Admin'))
@section('page-title', 'Productos')
@section('page-subtitle', 'Catalogo comercial homologado por empresa')

@section('content')
    <x-ui.table-card title="Productos registrados">
        <x-slot:actions>
            @can('products.create')
                <a class="btn btn-primary btn-sm" href="{{ route('parameters.products.create') }}">
                    <i class="ti ti-plus me-1" aria-hidden="true"></i>Nuevo producto
                </a>
            @endcan
        </x-slot:actions>

        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Codigo interno</th>
                    <th>Descripcion</th>
                    <th>Categoria</th>
                    <th>Unidad</th>
                    <th>Homologacion SIN</th>
                    <th class="text-end">Precio</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    <tr>
                        <td class="fw-semibold text-nowrap">{{ $product->internal_code }}</td>
                        <td>{{ $product->description }}</td>
                        <td>{{ $product->category?->name ?? '-' }}</td>
                        <td class="text-nowrap">
                            <span class="fw-semibold">{{ $product->measurement_unit_code }}</span>
                            @if ($product->measurement_unit_description)
                                <span class="text-body-secondary">- {{ $product->measurement_unit_description }}</span>
                            @endif
                        </td>
                        <td class="text-nowrap">
                            <div><span class="text-body-secondary">Actividad:</span> {{ $product->economic_activity_code }}</div>
                            <div><span class="text-body-secondary">Producto:</span> {{ $product->siat_product_code }}</div>
                        </td>
                        <td class="text-end text-nowrap">Bs {{ money_format_decimal($product->unit_price) }}</td>
                        <td>
                            <span class="badge text-bg-{{ $product->is_active ? 'success' : 'secondary' }}">
                                {{ $product->is_active ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td class="text-end text-nowrap">
                            @can('update', $product)
                                <a class="btn btn-outline-primary btn-sm" href="{{ route('parameters.products.edit', $product) }}">
                                    <i class="ti ti-edit me-1" aria-hidden="true"></i>Editar
                                </a>
                            @endcan

                            @can('delete', $product)
                                <form class="d-inline" method="POST" action="{{ route('parameters.products.destroy', $product) }}" data-confirm-delete="Eliminar producto?">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm" type="submit">
                                        <i class="ti ti-trash me-1" aria-hidden="true"></i>Eliminar
                                    </button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row colspan="8" message="No hay productos registrados." />
                @endforelse
            </tbody>
        </table>

        <x-slot:footer>{{ $products->links() }}</x-slot:footer>
    </x-ui.table-card>
@endsection
