<form method="POST" action="{{ route('users.assign-roles', $user) }}" data-ajax-form data-refresh-url="{{ route('users.index') }}" novalidate>
    @csrf
    @method('PATCH')

    <p class="text-body-secondary">Asignar roles a <strong>{{ $user->name }}</strong>.</p>

    @if (\App\Support\ReservedSuperAdmin::is($user))
        <div class="alert alert-info">El rol super_admin de esta cuenta es permanente.</div>
        <input type="hidden" name="roles[]" value="super_admin">
    @endif
    <div class="row g-2">
        @foreach ($roles as $role)
            <div class="col-md-6">
                <div class="form-check border rounded p-2 ps-5 bg-light">
                    <input class="form-check-input" id="assign-role-{{ $role->id }}" name="roles[]" type="checkbox" value="{{ $role->name }}" @disabled(\App\Support\ReservedSuperAdmin::is($user) && $role->name === \App\Support\ReservedSuperAdmin::ROLE) @checked($user->hasRole($role->name))>
                    <label class="form-check-label" for="assign-role-{{ $role->id }}">{{ role_label($role->name) }}</label>
                </div>
            </div>
        @endforeach
    </div>
    <div class="invalid-feedback d-block" data-error-for="roles"></div>

    <div class="d-flex justify-content-end gap-2 mt-4">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" type="submit">
            <span class="spinner-border spinner-border-sm me-2 d-none" data-submit-spinner aria-hidden="true"></span>
            Guardar roles
        </button>
    </div>
</form>
