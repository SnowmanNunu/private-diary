<?php

namespace App\Console\Commands;

use App\Services\BackupManager;
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

        try {
            $path = app(BackupManager::class)->create();
            $this->info('备份完成：'.$path);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('备份失败：'.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
