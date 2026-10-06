@extends('layouts.admin')

@section('title', 'Respaldos')

@section('content')
<div class="backup-vault">
    <header class="backup-vault__header">
        <div>
            <span class="backup-vault__eyebrow"><i class="ti ti-shield-lock"></i> Bóveda del sistema</span>
            <h2>Respaldos de base de datos</h2>
            <p>Crea puntos de recuperación, descárgalos y restaura el sistema cuando sea necesario.</p>
        </div>
        <div class="d-flex flex-column flex-sm-row gap-2">
            @can('backups.restore')
                <button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#uploadRestoreModal"><i class="ti ti-upload me-1"></i> Subir para restaurar</button>
            @endcan
            @can('backups.create')
                <form method="POST" action="{{ route('backups.store') }}">
                    @csrf
                    <button class="btn btn-primary w-100" type="submit"><i class="ti ti-database-plus me-1"></i> Crear respaldo ahora</button>
                </form>
            @endcan
        </div>
    </header>

    <div class="backup-vault__notice" role="note">
        <i class="ti ti-info-circle"></i>
        <span>Los respaldos contienen toda la base de datos y se guardan comprimidos en un área privada del servidor.</span>
    </div>

    <section class="card backup-vault__card" aria-labelledby="backup-history-title">
        <div class="card-header">
            <div>
                <h3 class="card-title" id="backup-history-title">Historial de respaldos</h3>
                <div class="text-muted small">{{ $backups->count() }} {{ $backups->count() === 1 ? 'archivo disponible' : 'archivos disponibles' }}</div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Archivo</th><th>Creado</th><th>Tamaño</th><th>Verificación</th><th class="text-end">Acciones</th></tr></thead>
                <tbody>
                    @forelse ($backups as $backup)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <span class="backup-vault__file"><i class="ti ti-file-zip"></i></span>
                                    <div><strong class="d-block">{{ $backup['name'] }}</strong><small class="text-muted">PostgreSQL · SQL comprimido</small></div>
                                </div>
                            </td>
                            <td>{{ $backup['created_at']->setTimezone(new DateTimeZone(config('app.timezone')))->format('d/m/Y H:i:s') }}</td>
                            <td>{{ Number::fileSize($backup['size'], precision: 2) }}</td>
                            <td><code title="SHA-256: {{ $backup['checksum'] }}">{{ substr($backup['checksum'], 0, 12) }}…</code></td>
                            <td>
                                <div class="d-flex justify-content-end gap-2">
                                    @can('backups.download')
                                        <a class="btn btn-outline-secondary btn-sm" href="{{ route('backups.download', $backup['name']) }}"><i class="ti ti-download me-1"></i> Descargar</a>
                                    @endcan
                                    @can('backups.restore')
                                        <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#restoreBackupModal" data-backup-name="{{ $backup['name'] }}" data-restore-url="{{ route('backups.restore', $backup['name']) }}"><i class="ti ti-history me-1"></i> Restaurar</button>
                                    @endcan
                                    @can('backups.delete')
                                        <button class="btn btn-ghost-danger btn-sm" type="button" title="Eliminar respaldo" aria-label="Eliminar {{ $backup['name'] }}" data-bs-toggle="modal" data-bs-target="#deleteBackupModal" data-backup-name="{{ $backup['name'] }}" data-delete-url="{{ route('backups.destroy', $backup['name']) }}"><i class="ti ti-trash"></i></button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><div class="backup-vault__empty"><i class="ti ti-database-off"></i><strong>Aún no hay puntos de recuperación</strong><span>Crea el primer respaldo para proteger la información del sistema.</span></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

@can('backups.restore')
<div class="modal modal-blur fade" id="uploadRestoreModal" tabindex="-1" aria-labelledby="uploadRestoreTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('backups.upload-restore') }}" enctype="multipart/form-data" data-upload-restore-form>
            @csrf
            <div class="modal-header backup-restore__header">
                <div><span class="backup-vault__eyebrow">Recuperación externa</span><h2 class="modal-title" id="uploadRestoreTitle">Subir y restaurar</h2></div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="backup-upload-zone mb-3">
                    <i class="ti ti-file-upload"></i>
                    <div><label class="form-label mb-1" for="backup-file">Archivo de respaldo</label><div class="text-muted small">Selecciona el archivo <strong>.sql.gz</strong> que descargaste anteriormente.</div></div>
                    <input class="form-control mt-3 @error('backup_file') is-invalid @enderror" id="backup-file" type="file" name="backup_file" accept=".sql.gz,application/gzip" required>
                    @error('backup_file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="alert alert-warning"><i class="ti ti-shield-check me-1"></i> Primero se guardará el estado actual. La restauración se aplica en una sola transacción y solo se confirma después de verificarla.</div>
                <label class="form-label" for="upload-restore-confirmation">Escribe <strong>RESTAURAR</strong> para continuar</label>
                <input class="form-control @error('confirmation') is-invalid @enderror" id="upload-restore-confirmation" name="confirmation" autocomplete="off" required data-upload-confirmation>
                @error('confirmation')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="modal-footer">
                <button class="btn btn-link" type="button" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-danger" type="submit" disabled data-upload-submit><i class="ti ti-history me-1"></i> Subir y restaurar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal modal-blur fade" id="restoreBackupModal" tabindex="-1" aria-labelledby="restoreBackupTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" data-restore-form>
            @csrf
            <div class="modal-header backup-restore__header">
                <div><span class="backup-vault__eyebrow">Acción crítica</span><h2 class="modal-title" id="restoreBackupTitle">Restaurar base de datos</h2></div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p>Se reemplazarán los datos actuales con <strong data-restore-name></strong>.</p>
                <div class="alert alert-warning"><i class="ti ti-shield-check me-1"></i> Antes de restaurar se guardará el estado actual. Si una sentencia falla, todos los cambios se revierten automáticamente.</div>
                <label class="form-label" for="restore-confirmation">Escribe <strong>RESTAURAR</strong> para continuar</label>
                <input class="form-control @error('confirmation') is-invalid @enderror" id="restore-confirmation" name="confirmation" autocomplete="off" required data-restore-confirmation>
                @error('confirmation')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="modal-footer">
                <button class="btn btn-link" type="button" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-danger" type="submit" disabled data-restore-submit>Restaurar respaldo</button>
            </div>
        </form>
    </div>
</div>
@endcan

@can('backups.delete')
<div class="modal modal-blur fade" id="deleteBackupModal" tabindex="-1" aria-labelledby="deleteBackupTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" data-delete-form>
            @csrf
            @method('DELETE')
            <div class="modal-header backup-delete__header">
                <div><span class="backup-vault__eyebrow text-danger">Eliminación permanente</span><h2 class="modal-title" id="deleteBackupTitle">Eliminar respaldo</h2></div>
                <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p>Se eliminará permanentemente <strong data-delete-name></strong>.</p>
                <div class="alert alert-danger"><i class="ti ti-alert-triangle me-1"></i> Este archivo ya no podrá descargarse ni utilizarse para una restauración.</div>
                <label class="form-label" for="delete-confirmation">Escribe <strong>ELIMINAR</strong> para continuar</label>
                <input class="form-control" id="delete-confirmation" name="confirmation" autocomplete="off" required data-delete-confirmation>
            </div>
            <div class="modal-footer">
                <button class="btn btn-link" type="button" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-danger" type="submit" disabled data-delete-submit>Eliminar respaldo</button>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection

@push('styles')
<style>
.backup-vault{--vault-ink:#18324a;--vault-blue:#176b87;--vault-mist:#eef7f8;--vault-line:#d8e4e8}.backup-vault__header{display:flex;align-items:end;justify-content:space-between;gap:2rem;padding:1.75rem 0 1.25rem;border-bottom:1px solid var(--vault-line)}.backup-vault__header h2{margin:.35rem 0 .4rem;color:var(--vault-ink);font-size:clamp(1.7rem,3vw,2.35rem);letter-spacing:-.035em}.backup-vault__header p{margin:0;max-width:46rem;color:#627585}.backup-vault__eyebrow{color:var(--vault-blue);font-size:.72rem;font-weight:800;letter-spacing:.13em;text-transform:uppercase}.backup-vault__notice{display:flex;gap:.7rem;align-items:center;margin:1.25rem 0;padding:.8rem 1rem;background:var(--vault-mist);border-left:3px solid var(--vault-blue);color:#365b69}.backup-vault__card{border-color:var(--vault-line);box-shadow:0 12px 32px rgba(24,50,74,.07)}.backup-vault__card .card-header{min-height:4.5rem}.backup-vault__file{display:grid;place-items:center;width:2.5rem;height:2.5rem;border-radius:.65rem;color:var(--vault-blue);background:var(--vault-mist);font-size:1.25rem}.backup-vault__empty{display:flex;min-height:15rem;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;color:#71818e;text-align:center}.backup-vault__empty i{font-size:2.7rem;color:#a4b5be}.backup-vault__empty strong{color:var(--vault-ink);font-size:1rem}.backup-restore__header,.backup-delete__header{border-top:4px solid #d63939}.backup-upload-zone{padding:1rem;border:1px dashed #9eb5c0;border-radius:.75rem;background:#f5f9fa}.backup-upload-zone>i{float:left;margin:.1rem .75rem 0 0;color:var(--vault-blue);font-size:1.7rem}@media(max-width:767.98px){.backup-vault__header{align-items:stretch;flex-direction:column}.backup-vault__header .btn{width:100%}}
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded',()=>{const modal=document.getElementById('restoreBackupModal');if(modal){const form=modal.querySelector('[data-restore-form]');const input=modal.querySelector('[data-restore-confirmation]');const submit=modal.querySelector('[data-restore-submit]');modal.addEventListener('show.bs.modal',event=>{const button=event.relatedTarget;form.action=button.dataset.restoreUrl;modal.querySelector('[data-restore-name]').textContent=button.dataset.backupName;input.value='';submit.disabled=true});input.addEventListener('input',()=>{submit.disabled=input.value!=='RESTAURAR'})}const uploadModal=document.getElementById('uploadRestoreModal');if(uploadModal){const input=uploadModal.querySelector('[data-upload-confirmation]');const submit=uploadModal.querySelector('[data-upload-submit]');uploadModal.addEventListener('show.bs.modal',()=>{input.value='';submit.disabled=true});input.addEventListener('input',()=>{submit.disabled=input.value!=='RESTAURAR'})}const deleteModal=document.getElementById('deleteBackupModal');if(deleteModal){const form=deleteModal.querySelector('[data-delete-form]');const input=deleteModal.querySelector('[data-delete-confirmation]');const submit=deleteModal.querySelector('[data-delete-submit]');deleteModal.addEventListener('show.bs.modal',event=>{const button=event.relatedTarget;form.action=button.dataset.deleteUrl;deleteModal.querySelector('[data-delete-name]').textContent=button.dataset.backupName;input.value='';submit.disabled=true});input.addEventListener('input',()=>{submit.disabled=input.value!=='ELIMINAR'})}});
</script>
@endpush
