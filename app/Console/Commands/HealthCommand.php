<?php

namespace App\Console\Commands;

use App\Services\BackupHealth;
use App\Services\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthCommand extends Command
{
    protected $signature = 'diary:health';

    protected $description = '自检迁移状态、数据库完整性、全文索引、孤立附件与备份新鲜度，并在异常时发通知';

    public function handle(): int
    {
        $checks = [];
        $failed = false;

        // 代码升级后若忘记执行迁移，写入 revision 等新列会直接 500，
        // 而离线同步只会显示「同步失败」。这里把待执行迁移显式报出来。
        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
            $files = glob(database_path('migrations/*.php')) ?: [];
            $pending = array_values(array_diff(
                array_map(static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME), $files),
                $ran,
            ));
            $checks[] = ['数据库迁移', $pending
                ? '待执行 '.count($pending).' 项：'.implode(', ', $pending).'（请运行 php artisan migrate --force）'
                : 'ok'];
            $failed = $failed || $pending !== [];
        } catch (Throwable $exception) {
            $checks[] = ['数据库迁移', '失败：'.$exception->getMessage()];
            $failed = true;
        }

        try {
            $integrity = DB::selectOne('PRAGMA integrity_check');
            $result = (string) ($integrity->integrity_check ?? 'unknown');
            $checks[] = ['数据库完整性', $result === 'ok' ? 'ok' : '失败：'.$result];
            $failed = $failed || $result !== 'ok';
        } catch (Throwable $exception) {
            $checks[] = ['数据库完整性', '失败：'.$exception->getMessage()];
            $failed = true;
        }

        try {
            DB::statement("INSERT INTO entries_fts(entries_fts) VALUES('integrity-check')");
            $checks[] = ['全文索引', 'ok'];
        } catch (Throwable $exception) {
            $checks[] = ['全文索引', '失败：'.$exception->getMessage()];
            $failed = true;
        }

        try {
            $orphans = DB::table('attachments')
                ->leftJoin('entries', 'attachments.entry_id', '=', 'entries.id')
                ->whereNull('entries.id')
                ->count();
            $checks[] = ['孤立附件', $orphans === 0 ? 'ok' : $orphans.' 个（可用导入/导出重建）'];
        } catch (Throwable $exception) {
            $checks[] = ['孤立附件', '跳过：'.$exception->getMessage()];
        }

        $health = app(BackupHealth::class)->status();
        $checks[] = ['备份状态', $health['message']];

        foreach ($checks as [$label, $value]) {
            $this->line($label.'：'.$value);
        }

        if ($failed === false && $health['level'] !== 'ok' && $health['level'] !== 'unknown') {
            $key = 'diary:health:notified:'.$health['level'].':'.now()->toDateString();

            if (! Cache::has($key)) {
                app(Notifier::class)->send(
                    '备份健康提醒 · '.config('app.name'),
                    $health['message']."\n时间：".now()->toDateTimeString(),
                    'warning',
                );
                Cache::put($key, true, now()->addHours(20));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
