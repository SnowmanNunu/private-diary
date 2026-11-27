<?php

namespace App\Services;

use App\Models\BackupAudit;

class BackupHealth
{
    public function __construct(private Settings $settings)
    {
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $hours = $this->settings->notifications()['alert_hours'];

        $lastBackup = BackupAudit::query()->where('kind', 'backup')->orderByDesc('checked_at')->first();
        $lastOkBackup = BackupAudit::query()->where('kind', 'backup')->where('status', 'ok')->orderByDesc('checked_at')->first();
        $lastVerify = BackupAudit::query()->where('kind', 'verify')->orderByDesc('checked_at')->first();

        $stale = $lastOkBackup === null
            || $lastOkBackup->checked_at->lt(now()->subHours($hours));

        $level = match (true) {
            $lastBackup?->status === 'failed' => 'error',
            $lastBackup === null => 'unknown',
            $stale => 'warning',
            $lastVerify?->status === 'failed' => 'warning',
            default => 'ok',
        };

        $message = match ($level) {
            'error' => '最近一次备份失败，请检查存储凭证与磁盘空间。',
            'unknown' => '还没有备份记录，建议先手动备份一次。',
            'warning' => $stale
                ? '已超过 '.$hours.' 小时没有成功备份。'
                : '最近一次恢复演练失败，建议检查备份完整性。',
            default => '备份与演练状态正常。',
        };

        return [
            'level' => $level,
            'message' => $message,
            'stale' => $stale,
            'alert_hours' => $hours,
            'last_backup_at' => $lastBackup?->checked_at,
            'last_backup_status' => $lastBackup?->status ?? 'missing',
            'last_backup_path' => $lastBackup?->backup_path,
            'last_verify_at' => $lastVerify?->checked_at,
            'last_verify_status' => $lastVerify?->status ?? 'missing',
        ];
    }
}
