<dl class="row mb-0">
    <dt class="col-sm-3">Nombre</dt>
    <dd class="col-sm-9">{{ role_label($role->name) }}</dd>
    <dt class="col-sm-3">Contexto de autenticación</dt>
    <dd class="col-sm-9">{{ authentication_context_label($role->guard_name) }}</dd>
    <dt class="col-sm-3">Usuarios</dt>
    <dd class="col-sm-9">{{ $role->users_count }}</dd>
    <dt class="col-sm-3">Permisos</dt>
    <dd class="col-sm-9">
        @forelse ($role->permissions as $permission)
            <span class="badge text-bg-secondary">{{ permission_label($permission->name) }}</span>
        @empty
            <span class="text-body-secondary">Sin permisos</span>
        @endforelse
    </dd>
</dl>
