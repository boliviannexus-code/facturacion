@extends('layouts.admin')

@section('title', 'Clientes | '.config('app.name', 'Base Admin'))
@section('page-title', 'Clientes')
@section('page-subtitle', 'Datos para emision fiscal por empresa')

@section('content')
    <x-ui.table-card title="Clientes registrados">
        <x-slot:actions>
            @can('customers.create')
                <a class="btn btn-primary btn-sm" href="{{ route('parameters.customers.create') }}">
                    <i class="ti ti-plus me-1" aria-hidden="true"></i>Nuevo cliente
                </a>
            @endcan
        </x-slot:actions>

        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Documento</th>
                    <th>Codigo cliente</th>
                    <th>Contacto</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($customers as $customer)
                    <tr>
                        <td class="fw-semibold">{{ $customer->name }}</td>
                        <td>
                            <div>
                                {{ $customer->identity_document_type_code }}
                                @if ($customer->identity_document_type_description)
                                    - {{ $customer->identity_document_type_description }}
                                @endif
                            </div>
                            <div class="fw-semibold">{{ $customer->document_number }}</div>
                            @if ($customer->document_complement)
                                <div class="text-body-secondary small">Comp. {{ $customer->document_complement }}</div>
                            @endif
                        </td>
                        <td>{{ $customer->customer_code }}</td>
                        <td>
                            <div>{{ $customer->email ?: '-' }}</div>
                            <div class="text-body-secondary small">{{ $customer->phone ?: '-' }}</div>
                        </td>
                        <td>
                            <span class="badge text-bg-{{ $customer->is_active ? 'success' : 'secondary' }}">
                                {{ $customer->is_active ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td class="text-end">
                            @can('update', $customer)
                                <a class="btn btn-outline-primary btn-sm" href="{{ route('parameters.customers.edit', $customer) }}">
                                    <i class="ti ti-edit me-1" aria-hidden="true"></i>Editar
                                </a>
                            @endcan

                            @can('delete', $customer)
                                <form class="d-inline" method="POST" action="{{ route('parameters.customers.destroy', $customer) }}" data-confirm-delete="Eliminar cliente?">
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
                    <x-ui.empty-row colspan="6" message="No hay clientes registrados." />
                @endforelse
            </tbody>
        </table>

        <x-slot:footer>{{ $customers->links() }}</x-slot:footer>
    </x-ui.table-card>
@endsection
