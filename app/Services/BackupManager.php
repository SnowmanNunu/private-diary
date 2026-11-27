<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

class BackupManager
{
    public function __construct(private BackupCrypto $crypto)
    {
    }

    public function create(): string
    {
        $tempDir = $this->makeTempDir();

        try {
            $databasePath = $tempDir.'/database.sqlite';
            $this->snapshotDatabase($databasePath);

            $files = [
                'database.sqlite' => $this->hashFile($databasePath),
            ];

            foreach ($this->stagePrivateFiles($tempDir) as $relative => $hash) {
                $files[$relative] = $hash;
            }

            $manifest = [
                'format' => 'private-diary-backup/1',
                'created_at' => now()->toIso8601String(),
                'app_name' => config('app.name'),
                'php_version' => PHP_VERSION,
                'database' => 'sqlite',
                'files' => $files,
            ];

            $zipPath = $this->buildZip($tempDir, $manifest);
            $payload = (string) file_get_contents($zipPath);
            $encrypted = $this->crypto->encrypt($payload);

            $now = now();
            $name = 'private-diary-'.$now->format('Ymd-His').'.zip.enc';
            $prefix = $this->prefix();
            $remotePath = $prefix === ''
                ? $now->format('Y/m').'/'.$name
                : $prefix.'/'.$now->format('Y/m').'/'.$name;

            if (! Storage::disk($this->disk())->put($remotePath, $encrypted)) {
                throw new RuntimeException('备份上传失败');
            }

            $this->prune();

            return $remotePath;
        } finally {
            $this->deleteDirectory($tempDir);
        }
    }

    public function latestPath(): ?string
    {
        $disk = Storage::disk($this->disk());

        return collect($disk->allFiles($this->prefix()))
            ->filter(fn (string $path): bool => preg_match('/private-diary-\d{8}-\d{6}\.zip\.enc$/', $path) === 1)
            ->sortByDesc(fn (string $path): int => $disk->lastModified($path))
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(?string $path = null): array
    {
        $path ??= $this->latestPath();

        if ($path === null) {
            throw new RuntimeException('没有找到可校验的备份');
        }

        $encrypted = Storage::disk($this->disk())->get($path);

        if ($encrypted === null) {
            throw new RuntimeException('无法读取备份文件：'.$path);
        }

        $zipContent = $this->crypto->decrypt($encrypted);
        $tempDir = $this->makeTempDir();
        $zipPath = $tempDir.'/backup.zip';
        $extractDir = $tempDir.'/extract';

        if (file_put_contents($zipPath, $zipContent) === false) {
            $this->deleteDirectory($tempDir);
            throw new RuntimeException('无法写入临时备份文件');
        }

        if (! mkdir($extractDir, 0700, true) && ! is_dir($extractDir)) {
            $this->deleteDirectory($tempDir);
            throw new RuntimeException('无法创建临时恢复目录');
        }

        try {
            $this->extractZip($zipPath, $extractDir);
            $manifest = $this->readManifest($extractDir);

            foreach ($manifest['files'] as $relative => $expectedHash) {
                $this->assertSafePath((string) $relative);
                $fullPath = $extractDir.'/'.$relative;

                if (! is_file($fullPath) || ! hash_equals((string) $expectedHash, $this->hashFile($fullPath))) {
                    throw new RuntimeException('备份校验和不一致：'.$relative);
                }
            }

            $counts = $this->inspectDatabase($extractDir.'/database.sqlite');

            return [
                'path' => $path,
                'files_verified' => count($manifest['files']),
                'counts' => $counts,
                'checked_at' => now()->toIso8601String(),
            ];
        } finally {
            $this->deleteDirectory($tempDir);
        }
    }

    private function snapshotDatabase(string $target): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('自动备份目前仅支持 SQLite');
        }

        $source = $connection->getPdo();
        $destination = new PDO('sqlite:'.$target);
        $destination->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $destination->exec('PRAGMA foreign_keys = OFF');
        $destination->beginTransaction();

        try {
            $objects = $this->schemaObjects($source);

            foreach ($objects as $object) {
                if ($object['type'] === 'table') {
                    $destination->exec((string) $object['sql']);
                }
            }

            foreach ($objects as $object) {
                if ($object['type'] === 'table') {
                    $this->copyTable(
                        $source,
                        $destination,
                        (string) $object['name'],
                        ($object['table_kind'] ?? '') === 'virtual',
                    );
                }
            }

            foreach ($objects as $object) {
                if ($object['type'] !== 'table') {
                    $destination->exec((string) $object['sql']);
                }
            }

            $this->copySequences($source, $destination);
            $destination->commit();
        } catch (Throwable $exception) {
            if ($destination->inTransaction()) {
                $destination->rollBack();
            }

            throw new RuntimeException('数据库快照失败：'.$exception->getMessage(), 0, $exception);
        }

        if (! is_file($target)) {
            throw new RuntimeException('数据库快照创建失败');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function schemaObjects(PDO $source): array
    {
        $tableKinds = [];

        try {
            foreach ($source->query('PRAGMA table_list')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $tableKinds[(string) $row['name']] = (string) $row['type'];
            }
        } catch (Throwable) {
            // 旧版 SQLite 没有 table_list，按普通表处理
        }

        $rows = $source->query(
            "SELECT type, name, sql FROM sqlite_master
             WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%'
             ORDER BY CASE type
                 WHEN 'table' THEN 0
                 WHEN 'index' THEN 1
                 WHEN 'trigger' THEN 2
                 WHEN 'view' THEN 3
                 ELSE 4
             END, name",
        )->fetchAll(PDO::FETCH_ASSOC);

        $objects = [];

        foreach ($rows as $row) {
            $kind = $tableKinds[$row['name']] ?? 'table';

            if ($row['type'] === 'table' && $kind === 'shadow') {
                continue;
            }

            $row['table_kind'] = $kind;
            $objects[] = $row;
        }

        return $objects;
    }

    private function copyTable(PDO $source, PDO $destination, string $table, bool $virtual): void
    {
        $select = $virtual
            ? 'SELECT rowid AS "__rowid", * FROM '.$this->quoteIdentifier($source, $table)
            : 'SELECT * FROM '.$this->quoteIdentifier($source, $table);

        $rows = $source->query($select)->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return;
        }

        $columns = array_keys($rows[0]);
        $quotedColumns = array_map(
            fn (string $column): string => $this->quoteIdentifier(
                $destination,
                $virtual && $column === '__rowid' ? 'rowid' : $column,
            ),
            $columns,
        );

        $insert = $destination->prepare(
            'INSERT INTO '.$this->quoteIdentifier($destination, $table)
           .' ('.implode(', ', $quotedColumns).')'
           .' VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')',
        );

        foreach ($rows as $row) {
            foreach (array_values($row) as $index => $value) {
                $type = match (true) {
                    $value === null => PDO::PARAM_NULL,
                    is_int($value) => PDO::PARAM_INT,
                    default => PDO::PARAM_STR,
                };

                $insert->bindValue($index + 1, $value, $type);
            }

            $insert->execute();
        }
    }

    private function copySequences(PDO $source, PDO $destination): void
    {
        try {
            $rows = $source->query('SELECT name, seq FROM sqlite_sequence')->fetchAll(PDO::FETCH_ASSOC);

            if ($rows === []) {
                return;
            }

            $insert = $destination->prepare('INSERT OR REPLACE INTO sqlite_sequence (name, seq) VALUES (?, ?)');

            foreach ($rows as $row) {
                $insert->bindValue(1, (string) $row['name'], PDO::PARAM_STR);
                $insert->bindValue(2, (int) $row['seq'], PDO::PARAM_INT);
                $insert->execute();
            }
        } catch (Throwable) {
            // 没有 AUTOINCREMENT 表时忽略
        }
    }

    private function quoteIdentifier(PDO $pdo, string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    /**
     * @return array<string, string>
     */
    private function stagePrivateFiles(string $tempDir): array
    {
        $files = [];

        foreach (Storage::disk('local')->allFiles() as $path) {
            $this->assertSafePath($path);

            $target = $tempDir.'/private/'.$path;
            $this->ensureParentDirectory($target);

            $content = Storage::disk('local')->get($path);

            if ($content === null || file_put_contents($target, $content) === false) {
                throw new RuntimeException('附件打包失败：'.$path);
            }

            $files['private/'.$path] = $this->hashFile($target);
        }

        return $files;
    }

    /**
     * @param  array<string, string>  $manifest
     */
    private function buildZip(string $tempDir, array $manifest): string
    {
        $zipPath = $tempDir.'/backup.zip';
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('无法创建备份压缩包');
        }

        foreach ($manifest['files'] as $relative => $hash) {
            if ($relative === 'database.sqlite') {
                $zip->addFile($tempDir.'/'.$relative, $relative);
            }
        }

        foreach ($manifest['files'] as $relative => $hash) {
            if ($relative !== 'database.sqlite') {
                $zip->addFile($tempDir.'/'.$relative, (string) $relative);
            }
        }

        $zip->addFromString('manifest.json', json_encode(
            $manifest,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        ) ?: '{}');

        if (! $zip->close()) {
            throw new RuntimeException('备份压缩包写入失败');
        }

        return $zipPath;
    }

    private function extractZip(string $zipPath, string $extractDir): void
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('无法打开备份压缩包');
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            $this->assertSafePath($name);
        }

        if (! $zip->extractTo($extractDir)) {
            $zip->close();
            throw new RuntimeException('备份解压失败');
        }

        $zip->close();
    }

    /**
     * @return array<string, string>
     */
    private function readManifest(string $extractDir): array
    {
        $path = $extractDir.'/manifest.json';

        if (! is_file($path)) {
            throw new RuntimeException('备份缺少 manifest.json');
        }

        $manifest = json_decode((string) file_get_contents($path), true);

        if (! is_array($manifest)
            || ($manifest['format'] ?? null) !== 'private-diary-backup/1'
            || ! is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('备份清单无效');
        }

        return $manifest;
    }

    /**
     * @return array<string, int>
     */
    private function inspectDatabase(string $databasePath): array
    {
        if (! is_file($databasePath)) {
            throw new RuntimeException('备份缺少数据库快照');
        }

        $pdo = new PDO('sqlite:'.$databasePath);
        $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();

        if ($integrity !== 'ok') {
            throw new RuntimeException('备份数据库完整性检查失败');
        }

        $counts = [];

        foreach (['users', 'entries', 'tags', 'attachments'] as $table) {
            $exists = $pdo->query(
                "SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = ".$pdo->quote($table),
            )->fetchColumn();

            if ((int) $exists !== 1) {
                throw new RuntimeException('备份数据库缺少表：'.$table);
            }

            $counts[$table] = (int) $pdo->query('SELECT count(*) FROM '.$table)->fetchColumn();
        }

        return $counts;
    }

    private function prune(): void
    {
        $disk = Storage::disk($this->disk());

        $files = collect($disk->allFiles($this->prefix()))
            ->filter(fn (string $path): bool => preg_match('/private-diary-\d{8}-\d{6}\.zip\.enc$/', $path) === 1)
            ->sortByDesc(fn (string $path): int => $disk->lastModified($path))
            ->values();

        foreach ($files->slice((int) config('backup.keep', 30)) as $old) {
            $disk->delete($old);
        }
    }

    private function assertSafePath(string $path): void
    {
        if ($path === ''
            || str_contains($path, "\0")
            || str_contains($path, '..')
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            throw new RuntimeException('备份中存在不安全的文件路径');
        }
    }

    private function hashFile(string $path): string
    {
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new RuntimeException('无法计算文件校验和：'.$path);
        }

        return $hash;
    }

    private function ensureParentDirectory(string $path): void
    {
        $parent = dirname($path);

        if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
            throw new RuntimeException('无法创建临时目录：'.$parent);
        }
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/pd-backup-'.bin2hex(random_bytes(8));

        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('无法创建临时工作目录');
        }

        return $dir;
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    private function disk(): string
    {
        return (string) config('backup.disk', 'local');
    }

    private function prefix(): string
    {
        return trim((string) config('backup.path', ''), '/');
    }
}
