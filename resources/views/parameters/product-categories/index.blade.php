@extends('layouts.admin')

@section('title', 'Categorias | '.config('app.name', 'Base Admin'))
@section('page-title', 'Categorias')
@section('page-subtitle', 'Catalogo interno de productos por empresa')

@section('content')
    <x-ui.table-card title="Categorias registradas">
        <x-slot:actions>
            @can('product-categories.create')
                <a class="btn btn-primary btn-sm" href="{{ route('parameters.categories.create') }}">
                    <i class="ti ti-plus me-1" aria-hidden="true"></i>Nueva categoria
                </a>
            @endcan
        </x-slot:actions>

        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Categoria</th>
                    <th>Descripcion</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($categories as $category)
                    <tr>
                        <td class="fw-semibold">{{ $category->name }}</td>
                        <td>{{ $category->description ?: '-' }}</td>
                        <td>
                            <span class="badge text-bg-{{ $category->is_active ? 'success' : 'secondary' }}">
                                {{ $category->is_active ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                        <td class="text-end">
                            @can('update', $category)
                                <a class="btn btn-outline-primary btn-sm" href="{{ route('parameters.categories.edit', $category) }}">
                                    <i class="ti ti-edit me-1" aria-hidden="true"></i>Editar
                                </a>
                            @endcan

                            @can('delete', $category)
                                <form class="d-inline" method="POST" action="{{ route('parameters.categories.destroy', $category) }}" data-confirm-delete="Eliminar categoria?">
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
                    <x-ui.empty-row colspan="4" message="No hay categorias registradas." />
                @endforelse
            </tbody>
        </table>

        <x-slot:footer>{{ $categories->links() }}</x-slot:footer>
    </x-ui.table-card>
@endsection
