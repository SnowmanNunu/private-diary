<?php

namespace App\Console\Commands;

use App\Models\BackupAudit;
use App\Services\BackupManager;
use App\Services\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BackupCommand extends Command
{
    protected $signature = 'diary:backup {--disk= : 临时覆盖备份磁盘}';

    protected $description = '创建加密备份并上传到异地磁盘';

    public function handle(): int
    {
        $disk = $this->option('disk');

        if (is_string($disk) && $disk !== '') {
            config(['backup.disk' => $disk]);
        }

        $lock = Cache::lock('diary-backup', 600);

        if (! $lock->get()) {
            $this->error('已有备份任务正在运行');
            return self::FAILURE;
        }

        $started = microtime(true);

        try {
            $path = app(BackupManager::class)->create();

            $this->record('ok', ['path' => $path], $started);
            $this->info('备份完成：'.$path);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->record('failed', ['error' => $exception->getMessage()], $started);
            $this->error('备份失败：'.$exception->getMessage());
            report($exception);

            app(Notifier::class)->send(
                '备份失败 · '.config('app.name'),
                '自动备份失败：'.$exception->getMessage()."\n时间：".now()->toDateTimeString(),
                'error',
            );

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $details */
    private function record(string $status, array $details, float $started): void
    {
        try {
            BackupAudit::create([
                'kind' => 'backup',
                'backup_path' => $details['path'] ?? null,
                'status' => $status,
                'details' => $details,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'checked_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
