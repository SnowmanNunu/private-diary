<?php

namespace Tests\Feature;

use App\Models\BackupAudit;
use App\Models\Entry;
use App\Models\EntryConflict;
use App\Models\User;
use App\Services\BackupHealth;
use App\Services\Notifier;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiaryV06Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_conflicting_save_is_rejected_and_kept_as_a_copy(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/diary/2026-06-01', $this->payload('第一版', 0))
            ->assertOk()
            ->assertJsonPath('revision', 1);

        $this->actingAs($user)->postJson('/diary/2026-06-01', $this->payload('第二版', 1))
            ->assertOk()
            ->assertJsonPath('revision', 2);

        $this->actingAs($user)->postJson('/diary/2026-06-01', $this->payload('旧客户端的内容', 1))
            ->assertStatus(409)
            ->assertJsonPath('conflict', true)
            ->assertJsonPath('server_revision', 2)
            ->assertJsonPath('server.title', '第二版');

        $this->assertDatabaseHas('entries', ['title' => '第二版', 'revision' => 2]);
        $this->assertDatabaseHas('entry_conflicts', [
            'title' => '旧客户端的内容',
            'client_revision' => 1,
            'server_revision' => 2,
        ]);
    }

    public function test_force_overwrites_after_a_conflict(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/diary/2026-06-01', $this->payload('第一版', 0))->assertOk();
        $this->actingAs($user)->postJson('/diary/2026-06-01', $this->payload('第二版', 1))->assertOk();

        $this->actingAs($user)->postJson('/diary/2026-06-01', $this->payload('覆盖', 1) + ['force' => true])
            ->assertOk()
            ->assertJsonPath('revision', 3);

        $this->assertDatabaseHas('entries', ['title' => '覆盖', 'revision' => 3]);
    }

    public function test_conflict_copy_can_be_restored_or_deleted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/diary/2026-06-01', $this->payload('第一版', 0))->assertOk();
        $this->actingAs($user)->postJson('/diary/2026-06-01', $this->payload('第二版', 1))->assertOk();
        $this->actingAs($user)->postJson('/diary/2026-06-01', $this->payload('副本内容', 1))->assertStatus(409);

        $copy = EntryConflict::firstOrFail();

        $this->actingAs($user)->post(route('diary.conflict.restore', ['2026-06-01', $copy]))
            ->assertRedirect();

        $this->assertDatabaseHas('entries', ['title' => '副本内容']);
        $this->assertDatabaseCount('entry_conflicts', 0);

        $this->actingAs($user)->postJson('/diary/2026-06-01', $this->payload('另一份副本', 1))->assertStatus(409);
        $second = EntryConflict::firstOrFail();

        $this->actingAs($user)->delete(route('diary.conflict.destroy', ['2026-06-01', $second]))
            ->assertRedirect();
        $this->assertDatabaseCount('entry_conflicts', 0);
    }

    public function test_backup_health_reflects_records_and_staleness(): void
    {
        app(Settings::class)->save(['backup_alert_hours' => 36]);

        BackupAudit::create([
            'kind' => 'backup', 'status' => 'ok', 'backup_path' => 'a.enc',
            'details' => [], 'duration_ms' => 10, 'checked_at' => now(),
        ]);
        BackupAudit::create([
            'kind' => 'verify', 'status' => 'ok',
            'details' => [], 'duration_ms' => 10, 'checked_at' => now(),
        ]);

        $this->assertSame('ok', app(BackupHealth::class)->status()['level']);

        BackupAudit::where('kind', 'backup')->update(['checked_at' => now()->subDays(3)]);
        $this->assertSame('warning', app(BackupHealth::class)->status()['level']);

        BackupAudit::where('kind', 'backup')->update(['status' => 'failed', 'checked_at' => now()]);
        $this->assertSame('error', app(BackupHealth::class)->status()['level']);
    }

    public function test_failed_backup_is_recorded_and_notifies(): void
    {
        Http::fake(['https://hooks.example.com/*' => Http::response('ok', 200)]);

        app(Settings::class)->save([
            'notify_enabled' => true,
            'notify_email' => '',
            'notify_webhook' => 'https://hooks.example.com/diary',
        ]);

        config(['backup.passphrase' => null, 'backup.disk' => 'backup']);

        $this->artisan('diary:backup')->assertFailed();

        $this->assertDatabaseHas('backup_audits', ['kind' => 'backup', 'status' => 'failed']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://hooks.example.com/diary'
            && str_contains((string) $request['subject'], '备份失败'));
    }

    public function test_health_command_reports_stale_backup_and_notifies(): void
    {
        Http::fake(['https://hooks.example.com/*' => Http::response('ok', 200)]);

        app(Settings::class)->save([
            'notify_enabled' => true,
            'notify_email' => '',
            'notify_webhook' => 'https://hooks.example.com/diary',
            'backup_alert_hours' => 36,
        ]);

        BackupAudit::create([
            'kind' => 'backup', 'status' => 'ok', 'backup_path' => 'old.enc',
            'details' => [], 'duration_ms' => 10, 'checked_at' => now()->subDays(3),
        ]);

        $this->artisan('diary:health')->assertSuccessful()->expectsOutputToContain('已超过');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://hooks.example.com/diary'
            && str_contains((string) $request['subject'], '备份健康提醒'));
    }

    public function test_reminder_only_fires_when_today_is_empty(): void
    {
        Http::fake(['https://hooks.example.com/*' => Http::response('ok', 200)]);

        $user = User::factory()->create();

        app(Settings::class)->save([
            'notify_enabled' => true,
            'notify_email' => '',
            'notify_webhook' => 'https://hooks.example.com/diary',
            'reminder_enabled' => true,
            'reminder_time' => '21:00',
            'timezone' => 'UTC',
        ]);

        $this->artisan('diary:remind')->assertSuccessful();
        Http::assertSent(fn ($request): bool => str_contains((string) $request['subject'], '该写今天的日记了'));

        Cache::forget('diary:remind:'.now()->toDateString());

        Entry::create([
            'user_id' => $user->id,
            'entry_date' => now()->toDateString(),
            'title' => '今天写过了',
            'body' => '内容',
            'status' => 'published',
        ]);

        $this->artisan('diary:remind')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_streak_and_health_badge_appear_on_the_index(): void
    {
        $user = User::factory()->create();

        foreach ([now()->toDateString(), now()->subDay()->toDateString()] as $index => $date) {
            Entry::create([
                'user_id' => $user->id,
                'entry_date' => $date,
                'title' => '第 '.$index.' 天',
                'body' => '内容',
                'status' => 'published',
            ]);
        }

        $this->actingAs($user)->get('/diary')
            ->assertOk()
            ->assertSee('连续写作', false)
            ->assertSee('<strong>2</strong>', false)
            ->assertSee('备份状态', false);
    }

    public function test_settings_and_editor_expose_the_new_controls(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings')
            ->assertOk()
            ->assertSee('备份健康')
            ->assertSee('通知与提醒')
            ->assertSee('写作提醒')
            ->assertSee('发送测试通知');

        $this->actingAs($user)->get('/diary/create')
            ->assertOk()
            ->assertSee('id="editor-revision"', false)
            ->assertSee('id="focus-toggle"', false)
            ->assertSee('js/theme.js', false)
            ->assertSee('id="theme-toggle"', false);
    }

    public function test_service_worker_caches_theme_asset(): void
    {
        $this->assertStringContainsString("'/js/theme.js'", (string) file_get_contents(public_path('sw.js')));
        $this->assertStringContainsString("[data-theme=\"dark\"]", (string) file_get_contents(public_path('css/app.css')));
    }

    public function test_unsafe_webhook_is_never_called(): void
    {
        Http::fake();

        app(Settings::class)->save([
            'notify_enabled' => true,
            'notify_email' => '',
            'notify_webhook' => 'file:///etc/passwd',
        ]);

        $result = app(Notifier::class)->send('测试', '内容');

        $this->assertSame(0, $result['sent']);
        $this->assertStringContainsString('只支持 http/https', implode('；', $result['errors']));
        Http::assertNothingSent();
    }

    /** @return array<string, mixed> */
    private function payload(string $title, int $revision): array
    {
        return [
            'title' => $title,
            'body' => '正文 '.$title,
            'tags' => '生活',
            'mood' => '',
            'weather' => '',
            'autosave' => true,
            'revision' => $revision,
        ];
    }
}
