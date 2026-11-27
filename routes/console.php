<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('diary:backup')
    ->dailyAt('03:00')
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping(60);

Schedule::command('diary:backup:verify')
    ->cron('0 4 1 1,4,7,10 *')
    ->timezone('Asia/Shanghai')
    ->withoutOverlapping(120);
