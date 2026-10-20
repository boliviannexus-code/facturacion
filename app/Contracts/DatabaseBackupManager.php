<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

interface DatabaseBackupManager
{
    /** @return Collection<int, array{name: string, path: string, size: int, created_at: \DateTimeImmutable, checksum: string}> */
    public function all(): Collection;

    /** @return array{name: string, path: string, size: int, created_at: \DateTimeImmutable, checksum: string} */
    public function create(): array;

    /** @return array{name: string, path: string, size: int, created_at: \DateTimeImmutable, checksum: string} */
    public function import(UploadedFile $file): array;

    public function absolutePath(string $name): string;

    public function restore(string $name): void;

    public function restoreSafely(string $name, string $safetyBackup): void;

    public function delete(string $name): void;
}
