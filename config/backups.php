<?php

declare(strict_types=1);

return [
    'disk' => env('BACKUP_DISK', 'local'),
    'path' => env('BACKUP_PATH', 'backups/database'),
    'timeout' => (int) env('BACKUP_TIMEOUT', 600),
    'upload_max_kilobytes' => (int) env('BACKUP_UPLOAD_MAX_KB', 102400),
    'restore_max_bytes' => (int) env('BACKUP_RESTORE_MAX_BYTES', 2147483648),
    'required_tables' => ['migrations', 'users', 'companies', 'permissions', 'roles'],
];
