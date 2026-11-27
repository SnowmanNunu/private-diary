<?php

namespace App\Console\Commands;

use App\Models\BackupAudit;
use App\Services\BackupManager;
use App\Services\Notifier;
use Illuminate\Console\Command;
use Throwable;

class VerifyBackupCommand extends Command
{
    protected $signature = 'diary:backup:verify {--file= : 指定要校验的远端备份路径，默认最新一份}';

    protected $description = '下载最新备份并做恢复演练，不改动生产数据';

    public function handle(): int
    {
        $started = microtime(true);
        $path = $this->option('file');
        $path = is_string($path) ? $path : null;

        try {
            $manager = app(BackupManager::class);
            $path ??= $manager->latestPath();
            $result = $manager->verify($path);

            BackupAudit::create([
                'kind' => 'verify',
                'backup_path' => $result['path'],
                'status' => 'ok',
                'details' => $result,
                'duration_ms' => $this->duration($started),
                'checked_at' => now(),
            ]);

            $counts = $result['counts'];
            $this->info('恢复演练成功：'.$result['path']);
            $this->line('校验文件 '.$result['files_verified'].' 个；日记 '.$counts['entries'].' 篇，附件 '.$counts['attachments'].' 个。');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            BackupAudit::create([
                'kind' => 'verify',
                'backup_path' => $path,
                'status' => 'failed',
                'details' => ['error' => $exception->getMessage()],
                'duration_ms' => $this->duration($started),
                'checked_at' => now(),
            ]);

            $this->error('恢复演练失败：'.$exception->getMessage());

            app(Notifier::class)->send(
                '恢复演练失败 · '.config('app.name'),
                '备份恢复演练失败：'.$exception->getMessage()."\n时间：".now()->toDateTimeString(),
                'error',
            );

            return self::FAILURE;
        }
    }

    private function duration(float $started): int
    {
        return (int) ((microtime(true) - $started) * 1000);
    }
}
