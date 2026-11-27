<?php

return [
    'disk' => env('BACKUP_DISK', 'local'),

    'path' => trim((string) env('BACKUP_PATH', 'private-backups'), '/'),

    'passphrase' => env('BACKUP_PASSPHRASE'),

    'keep' => max(1, (int) env('BACKUP_KEEP', 30)),
];
