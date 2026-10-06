@php
    $selectedPermissions = collect(old('permissions', isset($role) ? $role->permissions->pluck('name')->all() : []));
    $permissionMatrixId = 'permission-matrix-'.($role->id ?? 'new');
    $totalPermissions = $permissionGroups->flatten()->count();
@endphp

<section class="permission-matrix" data-permission-matrix aria-labelledby="{{ $permissionMatrixId }}-title">
    <div class="permission-matrix-heading">
        <div>
            <span class="permission-matrix-eyebrow">Mapa de acceso</span>
            <h3 class="permission-matrix-title" id="{{ $permissionMatrixId }}-title">Permisos del rol</h3>
            <p class="permission-matrix-copy">Activa solamente lo que este rol necesita para realizar su trabajo.</p>
        </div>
        <div class="permission-matrix-total" aria-live="polite">
            <strong data-permission-selected-count>{{ $selectedPermissions->count() }}</strong>
            <span>de {{ $totalPermissions }} activos</span>
        </div>
    </div>

    <div class="permission-matrix-toolbar">
        <div class="permission-search">
            <i class="ti ti-search" aria-hidden="true"></i>
            <label class="visually-hidden" for="{{ $permissionMatrixId }}-search">Buscar permisos</label>
            <input
                class="form-control"
                id="{{ $permissionMatrixId }}-search"
                type="search"
                placeholder="Buscar modulo o accion..."
                autocomplete="off"
                data-permission-search
            >
        </div>
        <div class="permission-toolbar-actions" aria-label="Acciones masivas">
            <button class="btn btn-outline-primary btn-sm" type="button" data-permission-select-visible>
                <i class="ti ti-checks me-1" aria-hidden="true"></i>Activar visibles
            </button>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-permission-clear>
                Limpiar seleccion
            </button>
        </div>
    </div>

    <p class="permission-search-empty d-none" data-permission-empty role="status">
        <i class="ti ti-search-off" aria-hidden="true"></i>
        No encontramos permisos con esa busqueda.
    </p>

    <div class="permission-module-list">
        @foreach ($permissionGroups as $module => $permissions)
            @php
                $moduleSlug = str($module)->slug();
                $modulePanelId = "{$permissionMatrixId}-{$moduleSlug}";
                $moduleSelected = $permissions->whereIn('name', $selectedPermissions)->count();
            @endphp
            <article
                class="permission-module"
                data-permission-module
                data-search-text="{{ str(permission_module_label($module).' '.$module)->lower() }}"
            >
                <div class="permission-module-header">
                    <button
                        class="permission-module-toggle"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#{{ $modulePanelId }}"
                        aria-expanded="{{ $moduleSelected > 0 ? 'true' : 'false' }}"
                        aria-controls="{{ $modulePanelId }}"
                    >
                        <span class="permission-module-icon"><i class="ti ti-folder-shield" aria-hidden="true"></i></span>
                        <span class="permission-module-name">
                            <strong>{{ permission_module_label($module) }}</strong>
                            <small><span data-module-selected-count>{{ $moduleSelected }}</span> de {{ $permissions->count() }} activos</small>
                        </span>
                        <i class="ti ti-chevron-down permission-module-chevron" aria-hidden="true"></i>
                    </button>
                    <button class="btn btn-ghost-primary btn-sm permission-module-select" type="button" data-permission-select-module>
                        Activar todos
                    </button>
                </div>

                <div class="collapse {{ $moduleSelected > 0 ? 'show' : '' }}" id="{{ $modulePanelId }}" data-permission-module-panel>
                    <div class="permission-options">
                        @foreach ($permissions as $permission)
                            @php($action = str($permission->name)->after('.')->toString())
                            <label
                                class="permission-option"
                                for="{{ $permissionMatrixId }}-permission-{{ $permission->id }}"
                                data-permission-option
                                data-search-text="{{ str(permission_label($permission->name).' '.$permission->name)->lower() }}"
                            >
                                <input
                                    class="form-check-input"
                                    id="{{ $permissionMatrixId }}-permission-{{ $permission->id }}"
                                    name="permissions[]"
                                    type="checkbox"
                                    value="{{ $permission->name }}"
                                    data-permission-checkbox
                                    @checked($selectedPermissions->contains($permission->name))
                                >
                                <span class="permission-option-content">
                                    <span class="permission-option-title">{{ permission_action_label($action) }}</span>
                                    <span class="permission-option-description">{{ permission_action_description($action) }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </article>
        @endforeach
    </div>

    <div class="invalid-feedback d-block" data-error-for="permissions" role="alert">{{ ($errors ?? null)?->first('permissions') }}</div>
</section>
