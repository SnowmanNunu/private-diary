<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DiaryController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use Illuminate\Support\Facades\Route;

Route::get('/login', [SessionController::class, 'showLogin'])
    ->middleware('guest')
    ->name('login');

Route::post('/login', [SessionController::class, 'login'])
    ->middleware(['guest', 'throttle:6,1'])
    ->name('login.store');

Route::post('/logout', [SessionController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::get('/two-factor/challenge', [TwoFactorController::class, 'showChallenge'])
    ->name('two-factor.challenge');

Route::post('/two-factor/challenge', [TwoFactorController::class, 'verifyChallenge'])
    ->middleware('throttle:6,1')
    ->name('two-factor.challenge.store');

Route::middleware(['auth', EnsureTwoFactorIsVerified::class])->group(function (): void {
    Route::get('/', fn () => redirect()->route('diary.index'));

    Route::get('/security', [SecurityController::class, 'index'])->name('security.index');
    Route::get('/security/two-factor/setup', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
    Route::post('/security/two-factor/enable', [TwoFactorController::class, 'enable'])->name('two-factor.enable');
    Route::post('/security/two-factor/disable', [TwoFactorController::class, 'disable'])
        ->middleware('throttle:6,1')
        ->name('two-factor.disable');
    Route::post('/security/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
        ->middleware('throttle:6,1')
        ->name('two-factor.recovery-codes');

    Route::get('/diary', [DiaryController::class, 'index'])->name('diary.index');
    Route::get('/diary/create', [DiaryController::class, 'create'])->name('diary.create');
    Route::post('/diary/preview', [DiaryController::class, 'preview'])->name('diary.preview');

    Route::get('/calendar', [CalendarController::class, 'index'])->name('diary.calendar');
    Route::get('/stats', [StatsController::class, 'index'])->name('diary.stats');
    Route::get('/export', [ExportController::class, 'index'])->name('diary.export');
    Route::get('/import', [ImportController::class, 'index'])->name('diary.import');
    Route::post('/import/preview', [ImportController::class, 'preview'])->name('diary.import.preview');
    Route::post('/import', [ImportController::class, 'store'])->name('diary.import.store');
    Route::get('/export/json', [ExportController::class, 'json'])->name('diary.export.json');
    Route::get('/export/markdown', [ExportController::class, 'markdown'])->name('diary.export.markdown');

    Route::get('/diary/{date}', [DiaryController::class, 'show'])
        ->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
        ->name('diary.show');

    Route::post('/diary/{date}', [DiaryController::class, 'store'])
        ->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
        ->name('diary.store');

    Route::delete('/diary/{date}', [DiaryController::class, 'destroy'])
        ->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
        ->name('diary.destroy');

    Route::post('/diary/{date}/attachments', [AttachmentController::class, 'store'])
        ->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
        ->name('attachments.store');

    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show'])
        ->name('attachments.show');

    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])
        ->name('attachments.destroy');
});
