<?php

use App\Services\Settings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/** @var Settings $settingsService */
$settingsService = app(Settings::class);
$schedule = $settingsService->schedule();
$timezone = $schedule['timezone'];

Schedule::command('diary:health')
    ->dailyAt('09:00')
    ->timezone($timezone)
    ->withoutOverlapping(60);

$reminder = $settingsService->reminder();

if ($reminder['enabled']) {
    Schedule::command('diary:remind')
        ->dailyAt($reminder['time'])
        ->timezone($timezone)
        ->withoutOverlapping(60);
}

if ($schedule['enabled']) {
    Schedule::command('diary:backup')
        ->dailyAt($schedule['backup_time'])
        ->timezone($schedule['timezone'])
        ->withoutOverlapping(60);
}

if ($schedule['verify_enabled']) {
    Schedule::command('diary:backup:verify')
        ->dailyAt($schedule['verify_time'])
        ->timezone($schedule['timezone'])
        ->when(function () use ($schedule): bool {
            $now = now($schedule['timezone']);

            return (int) $now->day === $schedule['verify_day']
                && in_array((int) $now->month, [1, 4, 7, 10], true);
        })
        ->withoutOverlapping(120);
}
