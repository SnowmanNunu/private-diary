<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\BackupAudit;
use App\Models\Entry;
use App\Models\User;
use App\Services\BackupCrypto;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'backup.disk' => 'backup',
            'backup.path' => 'tests/backups',
            'backup.passphrase' => 'test-passphrase-at-least-16-chars',
            'backup.keep' => 30,
        ]);

        Storage::fake('backup');
        Storage::fake('local');
    }

    public function test_backup_is_encrypted_uploaded_and_can_be_verified(): void
    {
        $user = User::factory()->create();

        $entry = Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-11-27',
            'title' => '备份日记',
            'body' => '需要被备份的内容',
            'status' => 'published',
        ]);

        Attachment::create([
            'user_id' => $user->id,
            'entry_id' => $entry->id,
            'path' => 'attachments/1/photo.jpg',
            'original_name' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size' => 18,
        ]);

        Storage::disk('local')->put('attachments/1/photo.jpg', 'private-image-bytes');

        $this->artisan('diary:backup')->assertSuccessful();

        $files = Storage::disk('backup')->allFiles('tests/backups');
        $this->assertCount(1, $files);

        $path = $files[0];
        $encrypted = (string) Storage::disk('backup')->get($path);
        $this->assertStringStartsWith('PDBK1', $encrypted);
        $this->assertStringNotContainsString('private-image-bytes', $encrypted);

        $zipContent = app(BackupCrypto::class)->decrypt($encrypted);
        $zipPath = tempnam(sys_get_temp_dir(), 'backup-test-');
        $this->assertNotFalse($zipPath);
        file_put_contents($zipPath, $zipContent);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertNotFalse($zip->getFromName('database.sqlite'));
        $this->assertSame('private-image-bytes', $zip->getFromName('private/attachments/1/photo.jpg'));

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('private-diary-backup/1', $manifest['format']);
        $this->assertArrayHasKey('database.sqlite', $manifest['files']);
        $this->assertArrayHasKey('private/attachments/1/photo.jpg', $manifest['files']);
        $zip->close();
        unlink($zipPath);

        $this->artisan('diary:backup:verify')->assertSuccessful();

        $audit = BackupAudit::where('kind', 'verify')->firstOrFail();
        $this->assertSame('ok', $audit->status);
        $this->assertSame($path, $audit->backup_path);
        $this->assertSame(1, $audit->details['counts']['entries']);
        $this->assertSame(1, $audit->details['counts']['attachments']);

        $this->assertDatabaseHas('entries', ['title' => '备份日记']);
    }

    public function test_tampered_backup_fails_restore_drill(): void
    {
        $this->artisan('diary:backup')->assertSuccessful();

        $path = Storage::disk('backup')->allFiles('tests/backups')[0];
        $encrypted = (string) Storage::disk('backup')->get($path);
        Storage::disk('backup')->put($path, $encrypted.'tampered');

        $this->artisan('diary:backup:verify')->assertFailed();

        $audit = BackupAudit::where('kind', 'verify')->firstOrFail();
        $this->assertSame('failed', $audit->status);
        $this->assertSame($path, $audit->backup_path);
    }

    public function test_retention_keeps_only_configured_number_of_backups(): void
    {
        config(['backup.keep' => 1]);

        $this->travelTo(Carbon::parse('2026-11-27 03:00:00'));
        $this->artisan('diary:backup')->assertSuccessful();

        $this->travelTo(Carbon::parse('2026-11-28 03:00:00'));
        $this->artisan('diary:backup')->assertSuccessful();

        $this->assertCount(1, Storage::disk('backup')->allFiles('tests/backups'));
    }

    public function test_backup_requires_passphrase(): void
    {
        config(['backup.passphrase' => null]);

        $this->artisan('diary:backup')->assertFailed();
        $this->assertCount(0, Storage::disk('backup')->allFiles());
    }
}
