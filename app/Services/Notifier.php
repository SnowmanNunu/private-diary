<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 单一用户的轻量通知出口：邮件与 Webhook 可任选其一或同时启用。
 * 通知失败只记日志，绝不影响备份/提醒主流程。
 */
class Notifier
{
    public function __construct(private Settings $settings)
    {
    }

    /** @return array{sent: int, errors: array<int, string>} */
    public function send(string $subject, string $body, string $level = 'info'): array
    {
        $config = $this->settings->notifications();
        $sent = 0;
        $errors = [];

        if (! $config['enabled']) {
            return ['sent' => 0, 'errors' => ['通知未启用']];
        }

        if ($config['email'] !== '') {
            try {
                Mail::raw($body, fn ($message) => $message->to($config['email'])->subject($subject));
                $sent++;
            } catch (Throwable $exception) {
                $errors[] = '邮件：'.$exception->getMessage();
            }
        }

        if ($config['webhook'] !== '') {
            $url = $config['webhook'];

            if (! $this->isSafeUrl($url)) {
                $errors[] = 'Webhook：只支持 http/https 地址';
            } else {
                try {
                    $response = Http::timeout(10)->post($url, [
                        'subject' => $subject,
                        'body' => $body,
                        'level' => $level,
                        'app' => config('app.name'),
                        'sent_at' => now()->toIso8601String(),
                    ]);

                    if ($response->successful()) {
                        $sent++;
                    } else {
                        $errors[] = 'Webhook：HTTP '.$response->status();
                    }
                } catch (Throwable $exception) {
                    $errors[] = 'Webhook：'.$exception->getMessage();
                }
            }
        }

        if ($sent === 0 && $errors === []) {
            $errors[] = '未配置通知渠道';
        }

        foreach ($errors as $error) {
            Log::warning('通知发送失败：'.$error, ['subject' => $subject]);
        }

        return ['sent' => $sent, 'errors' => $errors];
    }

    private function isSafeUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
