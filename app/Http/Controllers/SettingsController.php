<?php

namespace App\Http\Controllers;

use App\Models\BackupAudit;
use App\Services\Settings;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SettingsController extends Controller
{
    public function index(Settings $settings)
    {
        $values = $settings->all();

        return view('settings.index', [
            'values' => $values,
            'configured' => [
                'secret' => filled($values['s3_secret'] ?? null),
                'passphrase' => filled($values['backup_passphrase'] ?? null),
            ],
            'audits' => BackupAudit::query()->latest()->limit(5)->get(),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'backup_disk' => ['required', Rule::in(['local', 's3'])],
            'backup_path' => ['nullable', 'string', 'max:100'],
            'backup_keep' => ['required', 'integer', 'min:1', 'max:365'],
            'backup_passphrase' => ['nullable', 'string', 'max:255'],
            's3_key' => ['nullable', 'string', 'max:255'],
            's3_secret' => ['nullable', 'string', 'max:255'],
            's3_region' => ['nullable', 'string', 'max:100'],
            's3_bucket' => ['nullable', 'string', 'max:255'],
            's3_endpoint' => ['nullable', 'string', 'max:255'],
            's3_url' => ['nullable', 'string', 'max:255'],
            'schedule_enabled' => ['nullable', 'boolean'],
            'backup_time' => ['required', 'date_format:H:i'],
            'verify_enabled' => ['nullable', 'boolean'],
            'verify_day' => ['required', 'integer', 'min:1', 'max:28'],
            'verify_time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, callable $fail): void {
                try {
                    new DateTimeZone($value);
                } catch (Throwable) {
                    $fail('时区无效，例如 Asia/Shanghai。');
                }
            }],
        ]);

        if (! Hash::check($data['current_password'], Auth::user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => '当前密码不正确。',
            ]);
        }

        $values = [
            'backup_disk' => $data['backup_disk'],
            'backup_path' => trim((string) ($data['backup_path'] ?? '')) ?: 'private-backups',
            'backup_keep' => (int) $data['backup_keep'],
            's3_key' => (string) ($data['s3_key'] ?? ''),
            's3_region' => (string) ($data['s3_region'] ?? ''),
            's3_bucket' => (string) ($data['s3_bucket'] ?? ''),
            's3_endpoint' => (string) ($data['s3_endpoint'] ?? ''),
            's3_url' => (string) ($data['s3_url'] ?? ''),
            's3_path_style' => $request->boolean('s3_path_style'),
            'schedule_enabled' => $request->boolean('schedule_enabled'),
            'backup_time' => $data['backup_time'],
            'verify_enabled' => $request->boolean('verify_enabled'),
            'verify_day' => (int) $data['verify_day'],
            'verify_time' => $data['verify_time'],
            'timezone' => $data['timezone'],
        ];

        // 留空表示保留原有凭证，避免页面回显秘密信息。
        if (filled($data['s3_secret'] ?? null)) {
            $values['s3_secret'] = $data['s3_secret'];
        }

        if (filled($data['backup_passphrase'] ?? null)) {
            $values['backup_passphrase'] = $data['backup_passphrase'];
        }

        $settings->save($values);

        return back()->with('status', '设置已保存，备份与定时任务会立即使用新配置。');
    }

    public function testStorage(): JsonResponse
    {
        if (config('backup.disk') !== 's3') {
            return response()->json(['ok' => false, 'message' => '请先把备份磁盘切换为 S3 兼容存储。'], 422);
        }

        try {
            config()->set('filesystems.disks.s3.throw', true);

            $path = '.private-diary-probe-'.Str::random(8);

            if (! Storage::disk('s3')->put($path, 'probe')) {
                throw new RuntimeException('写入测试文件失败，请检查密钥、桶名与权限。');
            }

            Storage::disk('s3')->delete($path);

            return response()->json(['ok' => true, 'message' => '连接正常，测试文件已写入并删除。']);
        } catch (Throwable $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        } finally {
            config()->set('filesystems.disks.s3.throw', false);
        }
    }

    public function backupNow(): RedirectResponse
    {
        $code = Artisan::call('diary:backup');
        $output = trim(Artisan::output());

        return $code === 0
            ? back()->with('status', '备份完成：'.$output)
            : back()->withErrors(['backup' => '备份失败：'.$output]);
    }
}
