<?php

namespace App\Console\Commands;

use App\Models\Entry;
use App\Models\User;
use App\Services\DiaryStats;
use App\Services\Notifier;
use App\Services\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RemindCommand extends Command
{
    protected $signature = 'diary:remind';

    protected $description = '当天还没写日记时发送温和提醒';

    public function handle(): int
    {
        $settings = app(Settings::class);
        $prefs = $settings->reminder();

        if (! $prefs['enabled']) {
            $this->info('提醒未启用。');

            return self::SUCCESS;
        }

        $userId = User::query()->orderBy('id')->value('id');

        if ($userId === null) {
            return self::SUCCESS;
        }

        $today = now($prefs['timezone'])->toDateString();

        if (Entry::where('user_id', $userId)->where('entry_date', $today)->exists()) {
            $this->info('今天已经写过日记了。');

            return self::SUCCESS;
        }

        $key = 'diary:remind:'.$today;

        if (Cache::has($key)) {
            $this->info('今天已经提醒过了。');

            return self::SUCCESS;
        }

        $lines = ['今天还没有写日记，随手记两句吧。'];

        if ($prefs['streak_alert']) {
            $streak = app(DiaryStats::class)->streakFor((int) $userId);

            if ($streak > 0) {
                $lines[] = '已经连续写了 '.$streak.' 天，别让记录断在今天。';
            }
        }

        $result = app(Notifier::class)->send(
            '该写今天的日记了 · '.config('app.name'),
            implode("\n", $lines),
            'reminder',
        );

        if ($result['sent'] > 0) {
            Cache::put($key, true, now()->addHours(20));
            $this->info('提醒已发送。');
        } else {
            $this->warn('提醒未发送：'.implode('；', $result['errors']));
        }

        return self::SUCCESS;
    }
}
