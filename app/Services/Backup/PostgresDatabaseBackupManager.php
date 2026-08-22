<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Contracts\DatabaseBackupManager;
use App\Exceptions\DatabaseRestoreException;
use DateTimeImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

final class PostgresDatabaseBackupManager implements DatabaseBackupManager
{
    private FilesystemAdapter $disk;

    public function __construct()
    {
        $this->disk = Storage::disk((string) config('backups.disk'));
    }

    public function all(): Collection
    {
        return collect($this->disk->files($this->directory()))
            ->filter(fn (string $path): bool => str_ends_with($path, '.sql.gz'))
            ->map(fn (string $path): array => $this->details($path))
            ->sortByDesc(fn (array $backup): int => $backup['created_at']->getTimestamp())
            ->values();
    }

    public function create(): array
    {
        $this->ensurePostgres();
        $name = sprintf('facturacion-%s-%s.sql.gz', now()->format('Ymd-His'), bin2hex(random_bytes(3)));
        $path = $this->directory().'/'.$name;

        $this->disk->makeDirectory($this->directory());
        $stream = gzopen($this->disk->path($path), 'wb9');

        if ($stream === false) {
            throw new RuntimeException('No se pudo crear el archivo de respaldo.');
        }

        $process = new Process($this->dumpCommand(), null, $this->processEnvironment());
        $process->setTimeout((int) config('backups.timeout', 600));

        try {
            $process->run(function (string $type, string $buffer) use ($stream): void {
                if ($type === Process::OUT) {
                    gzwrite($stream, $buffer);
                }
            });
        } finally {
            gzclose($stream);
        }

        if (! $process->isSuccessful()) {
            $this->disk->delete($path);
            throw new RuntimeException('No se pudo generar el respaldo: '.trim($process->getErrorOutput()));
        }

        return $this->details($path);
    }

    public function absolutePath(string $name): string
    {
        $path = $this->validatedPath($name);

        if (! $this->disk->exists($path)) {
            throw new RuntimeException('El respaldo solicitado no existe.');
        }

        return $this->disk->path($path);
    }

    public function import(UploadedFile $file): array
    {
        $name = sprintf('facturacion-%s-%s.sql.gz', now()->format('Ymd-His'), bin2hex(random_bytes(3)));
        $path = $this->directory().'/'.$name;
        $source = fopen($file->getRealPath(), 'rb');

        if ($source === false) {
            throw new RuntimeException('No se pudo leer el archivo subido.');
        }

        $this->disk->makeDirectory($this->directory());

        try {
            if (! $this->disk->writeStream($path, $source)) {
                throw new RuntimeException('No se pudo guardar el respaldo subido.');
            }
        } finally {
            fclose($source);
        }

        try {
            $this->assertValidPostgresDump($this->disk->path($path));
        } catch (\Throwable $exception) {
            $this->disk->delete($path);
            throw $exception;
        }

        return $this->details($path);
    }

    public function restore(string $name): void
    {
        $this->ensurePostgres();
        $source = gzopen($this->absolutePath($name), 'rb');

        if ($source === false) {
            throw new RuntimeException('No se pudo leer el respaldo seleccionado.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'facturacion-restore-');
        $target = $temporaryPath === false ? false : fopen($temporaryPath, 'wb');

        if ($temporaryPath === false || $target === false) {
            gzclose($source);
            throw new RuntimeException('No se pudo preparar la restauración.');
        }

        $restoredBytes = 0;

        try {
            while (! gzeof($source)) {
                $chunk = gzread($source, 1024 * 1024);
                if ($chunk === false || fwrite($target, $chunk) === false) {
                    throw new RuntimeException('El archivo de respaldo está dañado o incompleto.');
                }
                $restoredBytes += strlen($chunk);
                if ($restoredBytes > (int) config('backups.restore_max_bytes', 2147483648)) {
                    throw new RuntimeException('El respaldo descomprimido supera el tamaño permitido.');
                }
            }
        } finally {
            gzclose($source);
            fclose($target);
        }

        try {
            $process = new Process($this->restoreCommand($temporaryPath), null, $this->processEnvironment());
            $process->setTimeout((int) config('backups.timeout', 600));
            $process->mustRun();
            $this->verifyRestoredDatabase();
        } catch (\Throwable $exception) {
            throw new RuntimeException('No se pudo restaurar la base de datos: '.trim($exception->getMessage()), 0, $exception);
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function restoreSafely(string $name, string $safetyBackup): void
    {
        try {
            $this->restore($name);
        } catch (\Throwable $restoreException) {
            try {
                $this->restore($safetyBackup);
            } catch (\Throwable $rollbackException) {
                throw new DatabaseRestoreException(
                    'Falló la restauración y también la recuperación del respaldo preventivo.',
                    false,
                    new RuntimeException($rollbackException->getMessage(), 0, $restoreException),
                );
            }

            throw new DatabaseRestoreException(
                'La restauración falló; el estado anterior fue recuperado con el respaldo preventivo.',
                true,
                $restoreException,
            );
        }
    }

    public function delete(string $name): void
    {
        $path = $this->validatedPath($name);

        if (! $this->disk->exists($path)) {
            throw new RuntimeException('El respaldo solicitado no existe.');
        }

        if (! $this->disk->delete($path)) {
            throw new RuntimeException('No se pudo eliminar el archivo de respaldo.');
        }
    }

    /** @return array{name: string, path: string, size: int, created_at: DateTimeImmutable, checksum: string} */
    private function details(string $path): array
    {
        return [
            'name' => basename($path),
            'path' => $path,
            'size' => (int) $this->disk->size($path),
            'created_at' => (new DateTimeImmutable)->setTimestamp($this->disk->lastModified($path)),
            'checksum' => hash_file('sha256', $this->disk->path($path)) ?: '',
        ];
    }

    /** @return list<string> */
    private function dumpCommand(): array
    {
        $db = config('database.connections.pgsql');

        return ['pg_dump', '--host='.(string) $db['host'], '--port='.(string) $db['port'], '--username='.(string) $db['username'], '--dbname='.(string) $db['database'], '--clean', '--if-exists', '--no-owner', '--no-privileges', '--format=plain'];
    }

    /** @return list<string> */
    private function restoreCommand(string $path): array
    {
        $db = config('database.connections.pgsql');

        return ['psql', '--host='.(string) $db['host'], '--port='.(string) $db['port'], '--username='.(string) $db['username'], '--dbname='.(string) $db['database'], '--set=ON_ERROR_STOP=1', '--single-transaction', '--file='.$path];
    }

    /** @return array<string, string> */
    private function processEnvironment(): array
    {
        return ['PGPASSWORD' => (string) config('database.connections.pgsql.password')];
    }

    private function validatedPath(string $name): string
    {
        if (! preg_match('/\Afacturacion-\d{8}-\d{6}-[a-f0-9]{6}\.sql\.gz\z/', $name)) {
            throw new RuntimeException('El nombre del respaldo no es válido.');
        }

        return $this->directory().'/'.$name;
    }

    private function directory(): string
    {
        return trim((string) config('backups.path', 'backups/database'), '/');
    }

    private function ensurePostgres(): void
    {
        if (config('database.default') !== 'pgsql') {
            throw new RuntimeException('El módulo de respaldos está configurado para PostgreSQL.');
        }
    }

    private function assertValidPostgresDump(string $path): void
    {
        $stream = gzopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException('El archivo no es un respaldo GZIP válido.');
        }

        try {
            $header = gzread($stream, 65536);
        } finally {
            gzclose($stream);
        }

        if ($header === false || ! str_contains($header, 'PostgreSQL database dump')) {
            throw new RuntimeException('El archivo no contiene un respaldo PostgreSQL compatible.');
        }
    }

    private function verifyRestoredDatabase(): void
    {
        $tables = array_values((array) config('backups.required_tables', []));

        if ($tables === []) {
            throw new RuntimeException('No hay tablas configuradas para verificar la restauración.');
        }

        $quotedTables = implode(', ', array_map(
            static fn (string $table): string => "'".str_replace("'", "''", $table)."'",
            $tables,
        ));
        $query = "SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname = 'public' AND tablename IN ({$quotedTables}); SELECT count(*) FROM migrations;";
        $db = config('database.connections.pgsql');
        $process = new Process([
            'psql', '--host='.(string) $db['host'], '--port='.(string) $db['port'],
            '--username='.(string) $db['username'], '--dbname='.(string) $db['database'],
            '--set=ON_ERROR_STOP=1', '--tuples-only', '--no-align', '--command='.$query,
        ], null, $this->processEnvironment());
        $process->setTimeout(60);
        $process->mustRun();

        $results = array_values(array_filter(array_map('trim', preg_split('/\R/', $process->getOutput()) ?: []), 'strlen'));

        if ((int) ($results[0] ?? 0) !== count($tables) || (int) ($results[1] ?? 0) < 1) {
            throw new RuntimeException('La verificación posterior detectó una restauración incompleta.');
        }
    }
}
