<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\Setting;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DiaryV04Test extends TestCase
{
    use RefreshDatabase;

    public function test_settings_require_login(): void
    {
        $this->get('/settings')->assertRedirect(route('login'));
        $this->put('/settings')->assertRedirect(route('login'));
        $this->post('/settings/test-storage')->assertRedirect(route('login'));
    }

    public function test_mood_and_weather_icons_render_on_editor_list_and_reader(): void
    {
        $user = User::factory()->create();
        Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-06-01',
            'title' => '图标日记',
            'body' => '正文',
            'mood' => 'happy',
            'weather' => 'sunny',
            'status' => 'published',
        ]);

        $this->actingAs($user)->get('/diary/create?date=2026-06-01')
            ->assertOk()
            ->assertSee('<svg class="diary-icon"', false);

        $this->actingAs($user)->get('/diary')
            ->assertOk()
            ->assertSee('<svg class="diary-icon"', false)
            ->assertSee('开心')
            ->assertSee('晴');

        $this->actingAs($user)->get('/diary/2026-06-01')
            ->assertOk()
            ->assertSee('<svg class="diary-icon"', false);
    }

    public function test_settings_are_saved_encrypted_and_applied_to_config(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings', $this->settingsPayload($user, [
            'backup_disk' => 's3',
            'backup_path' => 'diary-backups',
            'backup_keep' => '12',
            's3_bucket' => 'my-private-bucket',
            's3_secret' => 'super-secret-key',
            'backup_passphrase' => 'a-very-long-passphrase',
            'backup_time' => '05:30',
        ]))->assertRedirect();

        $this->assertSame('s3', config('backup.disk'));
        $this->assertSame('diary-backups', config('backup.path'));
        $this->assertSame(12, config('backup.keep'));
        $this->assertSame('my-private-bucket', config('filesystems.disks.s3.bucket'));

        $raw = (string) Setting::where('key', 's3_secret')->value('value');
        $this->assertStringNotContainsString('super-secret-key', $raw);
        $this->assertSame('super-secret-key', app(Settings::class)->all()['s3_secret']);

        $schedule = app(Settings::class)->schedule();
        $this->assertSame('05:30', $schedule['backup_time']);
        $this->assertSame('Asia/Shanghai', $schedule['timezone']);
    }

    public function test_wrong_password_cannot_change_settings(): void
    {
        $user = User::factory()->create();
        $originalDisk = config('backup.disk');

        $this->actingAs($user)->put('/settings', $this->settingsPayload($user, [
            'backup_disk' => 's3',
            'current_password' => 'wrong-password',
        ]))->assertSessionHasErrors('current_password');

        $this->assertNull(Setting::where('key', 'backup_disk')->first());
        $this->assertSame($originalDisk, config('backup.disk'));
    }

    public function test_blank_sensitive_fields_keep_existing_values_and_are_not_echoed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings', $this->settingsPayload($user, [
            's3_secret' => 'keep-this-secret',
            'backup_passphrase' => 'keep-this-passphrase',
        ]))->assertRedirect();

        $this->actingAs($user)->put('/settings', $this->settingsPayload($user, [
            's3_secret' => '',
            'backup_passphrase' => '',
        ]))->assertRedirect();

        $this->assertSame('keep-this-secret', app(Settings::class)->all()['s3_secret']);
        $this->assertSame('keep-this-passphrase', app(Settings::class)->all()['backup_passphrase']);

        $this->actingAs($user)->get('/settings')
            ->assertOk()
            ->assertDontSee('keep-this-secret')
            ->assertDontSee('keep-this-passphrase')
            ->assertSee('已保存，留空表示不修改');
    }

    public function test_invalid_schedule_values_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings', $this->settingsPayload($user, [
            'backup_time' => '25:99',
        ]))->assertSessionHasErrors('backup_time');

        $this->actingAs($user)->put('/settings', $this->settingsPayload($user, [
            'timezone' => 'Mars/Phobos',
        ]))->assertSessionHasErrors('timezone');
    }

    public function test_storage_test_respects_selected_disk(): void
    {
        $user = User::factory()->create();
        Storage::fake('s3');
        config()->set('backup.disk', 'local');

        $this->actingAs($user)->postJson('/settings/test-storage')
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->actingAs($user)->put('/settings', $this->settingsPayload($user, [
            'backup_disk' => 's3',
            's3_bucket' => 'probe-bucket',
        ]))->assertRedirect();

        $this->actingAs($user)->postJson('/settings/test-storage')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_backup_uses_settings_from_database(): void
    {
        Storage::fake('local');
        Storage::fake('s3');
        $user = User::factory()->create();

        app(Settings::class)->save([
            'backup_disk' => 's3',
            'backup_path' => 'configured-backups',
            'backup_passphrase' => 'test-passphrase-from-settings',
            'backup_keep' => 3,
        ]);

        $this->assertSame(0, Artisan::call('diary:backup'));

        $files = collect(Storage::disk('s3')->allFiles('configured-backups'));
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.zip.enc', $files->first());
    }

    /** @param array<string, mixed> $overrides */
    private function settingsPayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'current_password' => 'password',
            'backup_disk' => 'local',
            'backup_path' => 'private-backups',
            'backup_keep' => '30',
            's3_key' => '',
            's3_secret' => '',
            's3_region' => '',
            's3_bucket' => '',
            's3_endpoint' => '',
            's3_url' => '',
            'backup_passphrase' => '',
            'schedule_enabled' => '1',
            'backup_time' => '03:00',
            'verify_enabled' => '1',
            'verify_day' => '1',
            'verify_time' => '04:00',
            'timezone' => 'Asia/Shanghai',
        ], $overrides);
    }
}
