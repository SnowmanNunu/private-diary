<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 后台设置：敏感值加密存储，并在运行时覆盖配置文件，
 * 因此不需要修改 .env，也不需要执行 config:cache。
 */
class Settings
{
    /** @var array<string, string> */
    private const BACKUP_KEYS = [
        'backup_disk' => 'backup.disk',
        'backup_path' => 'backup.path',
        'backup_keep' => 'backup.keep',
        'backup_passphrase' => 'backup.passphrase',
    ];

    /** @var array<string, string> */
    private const S3_KEYS = [
        's3_key' => 'filesystems.disks.s3.key',
        's3_secret' => 'filesystems.disks.s3.secret',
        's3_region' => 'filesystems.disks.s3.region',
        's3_bucket' => 'filesystems.disks.s3.bucket',
        's3_url' => 'filesystems.disks.s3.url',
        's3_endpoint' => 'filesystems.disks.s3.endpoint',
        's3_path_style' => 'filesystems.disks.s3.use_path_style_endpoint',
    ];

    /** @var array<int, string> */
    public const EDITABLE_KEYS = [
        'backup_disk', 'backup_path', 'backup_keep', 'backup_passphrase',
        's3_key', 's3_secret', 's3_region', 's3_bucket', 's3_url', 's3_endpoint', 's3_path_style',
        'schedule_enabled', 'backup_time', 'verify_enabled', 'verify_day', 'verify_time', 'timezone',
    ];

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'backup_disk' => config('backup.disk', 'local'),
            'backup_path' => config('backup.path', 'private-backups'),
            'backup_keep' => (int) config('backup.keep', 30),
            'backup_passphrase' => config('backup.passphrase'),
            's3_key' => config('filesystems.disks.s3.key'),
            's3_secret' => config('filesystems.disks.s3.secret'),
            's3_region' => config('filesystems.disks.s3.region'),
            's3_bucket' => config('filesystems.disks.s3.bucket'),
            's3_url' => config('filesystems.disks.s3.url'),
            's3_endpoint' => config('filesystems.disks.s3.endpoint'),
            's3_path_style' => (bool) config('filesystems.disks.s3.use_path_style_endpoint', false),
            'schedule_enabled' => true,
            'backup_time' => '03:00',
            'verify_enabled' => true,
            'verify_day' => 1,
            'verify_time' => '04:00',
            'timezone' => 'Asia/Shanghai',
        ];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $values = $this->defaults();

        foreach ($this->stored() as $key => $value) {
            if (array_key_exists($key, $values)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /** @return array<string, mixed> */
    public function stored(): array
    {
        if (! $this->tableExists()) {
            return [];
        }

        $values = [];

        foreach (Setting::query()->pluck('value', 'key') as $key => $raw) {
            $values[(string) $key] = $this->decode((string) $raw);
        }

        return $values;
    }

    public function has(string $key): bool
    {
        $value = $this->stored()[$key] ?? null;

        return is_string($value) && $value !== '';
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! in_array($key, self::EDITABLE_KEYS, true)) {
                continue;
            }

            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $this->encode($value)],
            );
        }

        $this->apply();
    }

    public function apply(): void
    {
        try {
            $values = $this->stored();

            foreach (self::BACKUP_KEYS as $key => $configKey) {
                if (array_key_exists($key, $values)) {
                    config([$configKey => $values[$key]]);
                }
            }

            foreach (self::S3_KEYS as $key => $configKey) {
                if (array_key_exists($key, $values)) {
                    config([$configKey => $values[$key]]);
                }
            }
        } catch (Throwable) {
            // 迁移尚未执行或数据库不可用时，继续沿用环境变量配置。
        }
    }

    /** @return array<string, mixed> */
    public function schedule(): array
    {
        $values = $this->all();

        return [
            'enabled' => (bool) ($values['schedule_enabled'] ?? true),
            'backup_time' => (string) ($values['backup_time'] ?? '03:00'),
            'verify_enabled' => (bool) ($values['verify_enabled'] ?? true),
            'verify_day' => max(1, min(28, (int) ($values['verify_day'] ?? 1))),
            'verify_time' => (string) ($values['verify_time'] ?? '04:00'),
            'timezone' => (string) ($values['timezone'] ?? 'Asia/Shanghai'),
        ];
    }

    private function tableExists(): bool
    {
        try {
            return Schema::hasTable('settings');
        } catch (Throwable) {
            return false;
        }
    }

    private function encode(mixed $value): string
    {
        return Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function decode(string $raw): mixed
    {
        try {
            $json = Crypt::decryptString($raw);

            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }
}
