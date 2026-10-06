<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label" for="role-name">Nombre</label>
        <input class="form-control {{ ($errors ?? null)?->has('name') ? 'is-invalid' : '' }}" id="role-name" name="name" value="{{ old('name', $role->name ?? '') }}" required>
        <div class="invalid-feedback" data-error-for="name">{{ ($errors ?? null)?->first('name') }}</div>
    </div>
    <div class="col-md-4">
        <label class="form-label" for="role-guard-name">Contexto de autenticación</label>
        <input class="form-control {{ ($errors ?? null)?->has('guard_name') ? 'is-invalid' : '' }}" id="role-guard-name" name="guard_name" value="{{ old('guard_name', $role->guard_name ?? 'web') }}">
        <div class="form-text">Identificador técnico usado para autenticar el acceso.</div>
        <div class="invalid-feedback" data-error-for="guard_name">{{ ($errors ?? null)?->first('guard_name') }}</div>
    </div>
    <div class="col-12">
        <label class="form-label">Permisos</label>
        @include('roles.partials.permission-checkboxes')
    </div>
</div>
