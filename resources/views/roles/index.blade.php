@extends('layouts.admin')

@section('title', 'Roles | '.config('app.name', 'Base Admin'))
@section('page-title', 'Roles')
@section('page-subtitle', 'Administracion de perfiles y permisos')

@section('content')
    <x-ui.table-card title="Listado de roles" data-refresh-container>
        <x-slot:actions>
            @can('roles.create')
                <a class="btn btn-primary btn-sm" href="{{ route('roles.create') }}" data-modal-url="{{ route('roles.create') }}" data-modal-title="Nuevo rol" data-modal-size="xl">Nuevo rol</a>
            @endcan
        </x-slot:actions>
        <table class="table table-hover align-middle">
            <thead><tr><th>Rol</th><th>Usuarios</th><th>Permisos</th><th>Creado</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
                @forelse ($roles as $role)
                    <tr>
                        <td>{{ role_label($role->name) }}</td>
                        <td>{{ $role->users_count }}</td>
                        <td>{{ $role->permissions_count }}</td>
                        <td>{{ $role->created_at?->format('Y-m-d') }}</td>
                        <td class="text-end">
                            <a class="btn btn-outline-secondary btn-sm" href="{{ route('roles.show', $role) }}" data-modal-url="{{ route('roles.show', $role) }}" data-modal-title="Detalle de rol">Ver</a>
                            @can('update', $role)
                                <a class="btn btn-outline-primary btn-sm" href="{{ route('roles.edit', $role) }}" data-modal-url="{{ route('roles.edit', $role) }}" data-modal-title="Editar rol" data-modal-size="xl">Editar</a>
                            @endcan
                            @can('assignPermissions', $role)
                                <a class="btn btn-outline-info btn-sm" href="{{ route('roles.permissions.form', $role) }}" data-modal-url="{{ route('roles.permissions.form', $role) }}" data-modal-title="Configurar permisos" data-modal-size="xl">
                                    <i class="ti ti-shield-cog me-1" aria-hidden="true"></i>Configurar acceso
                                </a>
                            @endcan
                            @can('delete', $role)
                                <form class="d-inline" method="POST" action="{{ route('roles.destroy', $role) }}" data-confirm-delete="Eliminar rol?">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row colspan="5" message="No hay roles registrados." />
                @endforelse
            </tbody>
        </table>
        <x-slot:footer>{{ $roles->links() }}</x-slot:footer>
    </x-ui.table-card>
@endsection
