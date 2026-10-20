<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Contracts\DatabaseBackupManager;
use App\Exceptions\DatabaseRestoreException;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteDatabaseBackupRequest;
use App\Http\Requests\RestoreDatabaseBackupRequest;
use App\Http\Requests\UploadDatabaseBackupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class DatabaseBackupController extends Controller
{
    public function __construct(private readonly DatabaseBackupManager $backups) {}

    public function index(): View
    {
        return view('backups.index', ['backups' => $this->backups->all()]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $backup = $this->backups->create();

            return back()->with('success', "Respaldo {$backup['name']} creado correctamente.");
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'No se pudo crear el respaldo. Revisa la configuración del servidor.');
        }
    }

    public function download(string $backup): BinaryFileResponse
    {
        return response()->download($this->backups->absolutePath($backup), $backup, ['Content-Type' => 'application/gzip']);
    }

    public function restore(RestoreDatabaseBackupRequest $request, string $backup): RedirectResponse
    {
        try {
            $safetyBackup = $this->backups->create();
            $this->backups->restoreSafely($backup, $safetyBackup['name']);

            return redirect()->route('backups.index')->with('success', "Base de datos restaurada. Se creó antes el respaldo de seguridad {$safetyBackup['name']}.");
        } catch (DatabaseRestoreException $exception) {
            report($exception);

            return back()->with('error', $exception->safetyRecovered
                ? 'La restauración tuvo un error. El estado anterior fue recuperado automáticamente.'
                : 'Error crítico: no se pudo restaurar ni recuperar automáticamente el estado anterior. No realices más operaciones y revisa los registros.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'La restauración no pudo completarse. Revisa el registro del sistema antes de reintentarlo.');
        }
    }

    public function uploadAndRestore(UploadDatabaseBackupRequest $request): RedirectResponse
    {
        try {
            $safetyBackup = $this->backups->create();
            $uploadedBackup = $this->backups->import($request->file('backup_file'));
            $this->backups->restoreSafely($uploadedBackup['name'], $safetyBackup['name']);

            return redirect()->route('backups.index')->with(
                'success',
                "Archivo {$uploadedBackup['name']} subido y restaurado. El respaldo preventivo es {$safetyBackup['name']}.",
            );
        } catch (DatabaseRestoreException $exception) {
            report($exception);

            return back()->with('error', $exception->safetyRecovered
                ? 'El archivo contenía un error. El estado anterior fue recuperado automáticamente.'
                : 'Error crítico: no se pudo restaurar ni recuperar automáticamente el estado anterior. No realices más operaciones y revisa los registros.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'No se pudo restaurar el archivo subido. Verifica que sea un respaldo PostgreSQL válido.');
        }
    }

    public function destroy(DeleteDatabaseBackupRequest $request, string $backup): RedirectResponse
    {
        try {
            $this->backups->delete($backup);

            return redirect()->route('backups.index')->with('success', "Respaldo {$backup} eliminado correctamente.");
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'No se pudo eliminar el respaldo seleccionado.');
        }
    }
}
