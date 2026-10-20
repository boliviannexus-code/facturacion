<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use App\Contracts\DatabaseBackupManager;
use App\Exceptions\DatabaseRestoreException;
use App\Models\User;
use App\Services\Backup\PostgresDatabaseBackupManager;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class DatabaseBackupModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_list_and_create_backups(): void
    {
        $fake = new FakeDatabaseBackupManager;
        $this->app->instance(DatabaseBackupManager::class, $fake);
        $user = $this->userWithPermissions(['backups.view', 'backups.create']);

        $this->actingAs($user)->get(route('backups.index'))
            ->assertOk()
            ->assertSee('Respaldos de base de datos')
            ->assertSee(FakeDatabaseBackupManager::FILE);

        $this->actingAs($user)->post(route('backups.store'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, $fake->createCalls);
    }

    public function test_user_without_permission_cannot_access_backups(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('backups.index'))
            ->assertForbidden();
    }

    public function test_restore_requires_exact_confirmation_and_creates_safety_backup(): void
    {
        $fake = new FakeDatabaseBackupManager;
        $this->app->instance(DatabaseBackupManager::class, $fake);
        $user = $this->userWithPermissions(['backups.restore']);
        $route = route('backups.restore', FakeDatabaseBackupManager::FILE);

        $this->actingAs($user)->post($route, ['confirmation' => 'restaurar'])
            ->assertSessionHasErrors('confirmation');
        $this->assertSame(0, $fake->createCalls);

        $this->actingAs($user)->post($route, ['confirmation' => 'RESTAURAR'])
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, $fake->createCalls);
        $this->assertSame([[FakeDatabaseBackupManager::FILE, FakeDatabaseBackupManager::FILE]], $fake->safeRestores);
    }

    public function test_uploaded_backup_is_imported_and_restored_after_safety_backup(): void
    {
        $fake = new FakeDatabaseBackupManager;
        $this->app->instance(DatabaseBackupManager::class, $fake);
        $user = $this->userWithPermissions(['backups.restore']);
        $file = UploadedFile::fake()->createWithContent(
            'respaldo-descargado.sql.gz',
            gzencode("-- PostgreSQL database dump\n") ?: '',
        );

        $this->actingAs($user)->post(route('backups.upload-restore'), [
            'backup_file' => $file,
            'confirmation' => 'RESTAURAR',
        ])->assertRedirect(route('backups.index'))->assertSessionHas('success');

        $this->assertSame(1, $fake->createCalls);
        $this->assertSame(['respaldo-descargado.sql.gz'], $fake->imported);
        $this->assertSame([[FakeDatabaseBackupManager::FILE, FakeDatabaseBackupManager::FILE]], $fake->safeRestores);
    }

    public function test_uploaded_restore_rejects_wrong_extension_and_confirmation(): void
    {
        $fake = new FakeDatabaseBackupManager;
        $this->app->instance(DatabaseBackupManager::class, $fake);
        $user = $this->userWithPermissions(['backups.restore']);

        $this->actingAs($user)->post(route('backups.upload-restore'), [
            'backup_file' => UploadedFile::fake()->createWithContent('respaldo.zip', 'invalid'),
            'confirmation' => 'restaurar',
        ])->assertSessionHasErrors(['backup_file', 'confirmation']);

        $this->assertSame(0, $fake->createCalls);
        $this->assertSame([], $fake->imported);
        $this->assertSame([], $fake->safeRestores);
    }

    public function test_restore_reports_when_previous_state_was_recovered(): void
    {
        $fake = new FakeDatabaseBackupManager;
        $fake->restoreFailure = new DatabaseRestoreException('Falló y se recuperó.', true);
        $this->app->instance(DatabaseBackupManager::class, $fake);
        $user = $this->userWithPermissions(['backups.restore']);

        $this->actingAs($user)->post(route('backups.restore', FakeDatabaseBackupManager::FILE), [
            'confirmation' => 'RESTAURAR',
        ])->assertSessionHas('error', 'La restauración tuvo un error. El estado anterior fue recuperado automáticamente.');

        $this->assertSame(1, $fake->createCalls);
        $this->assertSame([[FakeDatabaseBackupManager::FILE, FakeDatabaseBackupManager::FILE]], $fake->safeRestores);
    }

    public function test_backup_deletion_requires_exact_confirmation_and_permission(): void
    {
        $fake = new FakeDatabaseBackupManager;
        $this->app->instance(DatabaseBackupManager::class, $fake);
        $route = route('backups.destroy', FakeDatabaseBackupManager::FILE);
        $authorized = $this->userWithPermissions(['backups.delete']);

        $this->actingAs($authorized)->delete($route, ['confirmation' => 'eliminar'])
            ->assertSessionHasErrors('confirmation');
        $this->assertSame([], $fake->deleted);

        $this->actingAs($authorized)->delete($route, ['confirmation' => 'ELIMINAR'])
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('success');
        $this->assertSame([FakeDatabaseBackupManager::FILE], $fake->deleted);

        $this->actingAs(User::factory()->create())->delete($route, ['confirmation' => 'ELIMINAR'])
            ->assertForbidden();
    }

    public function test_manager_accepts_only_a_gzip_postgres_dump(): void
    {
        $disk = 'backup-test';
        $root = sys_get_temp_dir().'/facturacion-backup-test-'.bin2hex(random_bytes(4));
        config([
            "filesystems.disks.{$disk}" => ['driver' => 'local', 'root' => $root, 'throw' => false],
            'backups.disk' => $disk,
            'backups.path' => 'backups/database',
        ]);
        $manager = new PostgresDatabaseBackupManager;

        $imported = $manager->import(UploadedFile::fake()->createWithContent(
            'respaldo.sql.gz',
            gzencode("--\n-- PostgreSQL database dump\n--\n") ?: '',
        ));

        $this->assertTrue(Storage::disk($disk)->exists($imported['path']));

        try {
            $manager->import(UploadedFile::fake()->createWithContent('falso.sql.gz', gzencode('otro contenido') ?: ''));
            $this->fail('El archivo inválido debió ser rechazado.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('PostgreSQL', $exception->getMessage());
        }

        $this->assertCount(1, Storage::disk($disk)->files('backups/database'));
        $manager->delete($imported['name']);
        $this->assertFalse(Storage::disk($disk)->exists($imported['path']));
        Storage::disk($disk)->deleteDirectory('backups');
    }

    public function test_postgres_restore_command_is_atomic_and_stops_on_first_error(): void
    {
        $manager = new PostgresDatabaseBackupManager;
        $method = new ReflectionMethod($manager, 'restoreCommand');
        $command = $method->invoke($manager, '/tmp/backup.sql');

        $this->assertContains('--single-transaction', $command);
        $this->assertContains('--set=ON_ERROR_STOP=1', $command);
    }

    /** @param list<string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }
}

final class FakeDatabaseBackupManager implements DatabaseBackupManager
{
    public const FILE = 'facturacion-20260818-120000-a1b2c3.sql.gz';

    public int $createCalls = 0;

    /** @var list<string> */
    public array $imported = [];

    /** @var list<array{string, string}> */
    public array $safeRestores = [];

    public ?DatabaseRestoreException $restoreFailure = null;

    /** @var list<string> */
    public array $deleted = [];

    public function all(): Collection
    {
        return collect([$this->backup()]);
    }

    public function create(): array
    {
        $this->createCalls++;

        return $this->backup();
    }

    public function absolutePath(string $name): string
    {
        return __FILE__;
    }

    public function import(UploadedFile $file): array
    {
        $this->imported[] = $file->getClientOriginalName();

        return $this->backup();
    }

    public function restore(string $name): void
    {
        // The feature flow uses restoreSafely().
    }

    public function restoreSafely(string $name, string $safetyBackup): void
    {
        $this->safeRestores[] = [$name, $safetyBackup];

        if ($this->restoreFailure) {
            throw $this->restoreFailure;
        }
    }

    public function delete(string $name): void
    {
        $this->deleted[] = $name;
    }

    private function backup(): array
    {
        return [
            'name' => self::FILE,
            'path' => 'backups/database/'.self::FILE,
            'size' => 1024,
            'created_at' => new DateTimeImmutable('2026-08-18 12:00:00'),
            'checksum' => str_repeat('a', 64),
        ];
    }
}
