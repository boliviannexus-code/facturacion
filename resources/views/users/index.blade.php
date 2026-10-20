@extends('layouts.admin')

@section('title', 'Usuarios | '.config('app.name', 'Base Admin'))
@section('page-title', 'Usuarios')
@section('page-subtitle', 'Administracion de accesos, roles y estado')

@section('content')
    <x-ui.table-card title="Listado de usuarios" data-refresh-container>
        <x-slot:actions>
            @can('users.create')
                <a
                    class="btn btn-primary btn-sm"
                    href="{{ route('users.create') }}"
                    data-modal-url="{{ route('users.create') }}"
                    data-modal-title="Nuevo usuario"
                >
                    Nuevo usuario
                </a>
            @endcan
        </x-slot:actions>

        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Email</th>
                    <th>Empresa</th>
                    <th>Roles</th>
                    <th>Estado</th>
                    <th>Creado</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr class="{{ $user->trashed() ? 'table-light text-body-secondary' : '' }}">
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->company?->name ?? 'Sin empresa' }}</td>
                        <td>
                            @forelse ($user->roles as $role)
                                <span class="badge text-bg-primary">{{ role_label($role->name) }}</span>
                            @empty
                                <span class="text-body-secondary">Sin roles</span>
                            @endforelse
                        </td>
                        <td>
                            <span class="badge text-bg-{{ $user->is_active ? 'success' : 'secondary' }}">{{ $user->is_active ? 'Activo' : 'Inactivo' }}</span>
                            @if ($user->trashed())
                                <span class="badge text-bg-danger">Eliminado</span>
                            @endif
                        </td>
                        <td>{{ $user->created_at->format('Y-m-d') }}</td>
                        <td class="text-end">
                            <a class="btn btn-outline-secondary btn-sm" href="{{ route('users.show', $user) }}" data-modal-url="{{ route('users.show', $user) }}" data-modal-title="Detalle de usuario">Ver</a>

                            @if (! $user->trashed())
                                @can('update', $user)
                                    <a class="btn btn-outline-primary btn-sm" href="{{ route('users.edit', $user) }}" data-modal-url="{{ route('users.edit', $user) }}" data-modal-title="Editar usuario">Editar</a>
                                @endcan

                                @can('changePassword', $user)
                                    <a class="btn btn-outline-warning btn-sm" href="{{ route('users.change-password.form', $user) }}" data-modal-url="{{ route('users.change-password.form', $user) }}" data-modal-title="Cambiar contraseña">Contraseña</a>
                                @endcan

                                @can('assignRoles', $user)
                                    <a class="btn btn-outline-info btn-sm" href="{{ route('users.roles.form', $user) }}" data-modal-url="{{ route('users.roles.form', $user) }}" data-modal-title="Asignar roles">Roles</a>
                                @endcan

                                @can('update', $user)
                                    @unless (\App\Support\ReservedSuperAdmin::is($user))
                                    <form class="d-inline" method="POST" action="{{ route('users.toggle-status', $user) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button class="btn btn-outline-secondary btn-sm" type="submit">{{ $user->is_active ? 'Desactivar' : 'Activar' }}</button>
                                    </form>
                                    @endunless
                                @endcan

                                @can('delete', $user)
                                    <form class="d-inline" method="POST" action="{{ route('users.destroy', $user) }}" data-confirm-delete="Eliminar usuario?">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm" type="submit">Eliminar</button>
                                    </form>
                                @endcan
                            @else
                                @can('restore', $user)
                                    <form class="d-inline" method="POST" action="{{ route('users.restore', $user->id) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button class="btn btn-outline-success btn-sm" type="submit">Restaurar</button>
                                    </form>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row colspan="7" message="No hay usuarios registrados." />
                @endforelse
            </tbody>
        </table>

        <x-slot:footer>
            {{ $users->links() }}
        </x-slot:footer>
    </x-ui.table-card>
@endsection
