<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Entry;
use App\Models\Tag;
use App\Models\User;
use App\Services\DiaryExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DiaryV02Test extends TestCase
{
    use RefreshDatabase;

    public function test_import_and_stats_require_login(): void
    {
        $this->get('/import')->assertRedirect(route('login'));
        $this->get('/stats')->assertRedirect(route('login'));
        $this->post('/import/preview')->assertRedirect(route('login'));
        $this->post('/import')->assertRedirect(route('login'));
    }

    public function test_json_import_creates_entries_tags_and_is_idempotent(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $payload = $this->payload([
            [
                'date' => '2026-01-02',
                'title' => '导入的日记',
                'body' => '来自备份的正文',
                'status' => 'published',
                'tags' => ['生活', '随笔'],
                'attachments' => [],
            ],
        ]);

        $response = $this->actingAs($user)
            ->post('/import/preview', ['file' => $this->jsonFile($payload)])
            ->assertRedirect(route('diary.import'));

        $this->assertSame(1, session('import_preview')['new']);
        $this->assertSame(0, session('import_preview')['conflict']);

        $token = session('import_token');

        $this->actingAs($user)->post('/import', ['token' => $token, 'strategy' => 'skip'])
            ->assertRedirect(route('diary.index'));

        $entry = Entry::where('user_id', $user->id)->where('entry_date', '2026-01-02')->firstOrFail();
        $this->assertSame('导入的日记', $entry->title);
        $this->assertSame(['生活', '随笔'], $entry->tags->pluck('name')->sort()->values()->all());

        $this->actingAs($user)
            ->post('/import/preview', ['file' => $this->jsonFile($payload)])
            ->assertRedirect(route('diary.import'));
        $this->assertSame(1, session('import_preview')['conflict']);

        $this->actingAs($user)->post('/import', ['token' => session('import_token'), 'strategy' => 'skip'])
            ->assertRedirect(route('diary.index'));

        $this->assertSame(1, Entry::where('user_id', $user->id)->count());
        $this->assertSame(2, Tag::where('user_id', $user->id)->count());
    }

    public function test_overwrite_replaces_existing_entry(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $existing = Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-01-02',
            'title' => '旧标题',
            'body' => '旧正文',
            'status' => 'published',
        ]);
        $existing->tags()->attach(Tag::create(['user_id' => $user->id, 'name' => '旧标签']));

        $payload = $this->payload([[
            'date' => '2026-01-02',
            'title' => '新标题',
            'body' => '新正文',
            'status' => 'draft',
            'tags' => ['新标签'],
            'attachments' => [],
        ]]);

        $this->actingAs($user)->post('/import/preview', ['file' => $this->jsonFile($payload)]);
        $this->actingAs($user)->post('/import', ['token' => session('import_token'), 'strategy' => 'overwrite'])
            ->assertRedirect(route('diary.index'));

        $existing->refresh();
        $this->assertSame('新标题', $existing->title);
        $this->assertSame('新正文', $existing->body);
        $this->assertSame('draft', $existing->status);
        $this->assertSame(['新标签'], $existing->tags->pluck('name')->all());
    }

    public function test_zip_import_restores_images_and_rewrites_links(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $entry = Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-03-04',
            'title' => '带图日记',
            'body' => '看这张 ![狗](/attachments/{id})',
            'status' => 'published',
        ]);
        $fakeImage = UploadedFile::fake()->image('original.jpg', 2, 2);
        $imageBytes = (string) file_get_contents($fakeImage->getRealPath());
        Storage::disk('local')->put('attachments/original.jpg', $imageBytes);
        $attachment = Attachment::create([
            'user_id' => $user->id,
            'entry_id' => $entry->id,
            'path' => 'attachments/original.jpg',
            'original_name' => '海边.jpg',
            'mime' => 'image/jpeg',
            'size' => strlen($imageBytes),
        ]);
        $entry->update(['body' => '看这张 ![狗](/attachments/'.$attachment->id.')']);

        $zipPath = tempnam(sys_get_temp_dir(), 'diary-export-');
        $this->assertNotFalse($zipPath);
        $this->actingAs($user);
        app(DiaryExporter::class)->writeMarkdownZip($zipPath);
        $zipBytes = file_get_contents($zipPath);
        $this->assertNotFalse($zipBytes);
        unlink($zipPath);

        $other = User::factory()->create();
        $this->actingAs($other)->post('/import/preview', [
            'file' => UploadedFile::fake()->createWithContent('diary.zip', $zipBytes),
        ])->assertRedirect(route('diary.import'));

        $this->assertSame(1, session('import_preview')['new']);
        $this->assertSame(1, session('import_preview')['images']);

        $this->actingAs($other)->post('/import', ['token' => session('import_token'), 'strategy' => 'skip'])
            ->assertRedirect(route('diary.index'));

        $imported = Entry::where('user_id', $other->id)->where('entry_date', '2026-03-04')->firstOrFail();
        $this->assertStringNotContainsString('/attachments/'.$attachment->id, $imported->body);
        $this->assertCount(1, $imported->attachments);

        $newAttachment = $imported->attachments->first();
        $this->assertStringContainsString('/attachments/'.$newAttachment->id, $imported->body);
        Storage::disk('local')->assertExists($newAttachment->path);
        $this->assertSame($imageBytes, Storage::disk('local')->get($newAttachment->path));
    }

    public function test_zip_with_traversal_path_is_rejected(): void
    {
        Storage::fake('local');

        $zipPath = tempnam(sys_get_temp_dir(), 'diary-bad-');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        $zip->addFromString('manifest.json', json_encode($this->payload([]), JSON_THROW_ON_ERROR));
        $zip->addFromString('../evil.txt', 'evil');
        $zip->close();

        $zipBytes = file_get_contents($zipPath);
        unlink($zipPath);

        $this->actingAs(User::factory()->create())
            ->post('/import/preview', ['file' => UploadedFile::fake()->createWithContent('bad.zip', $zipBytes)])
            ->assertRedirect(route('diary.import'))
            ->assertSessionHasErrors('file');
    }

    public function test_import_rejects_unsupported_format(): void
    {
        Storage::fake('local');

        $this->actingAs(User::factory()->create())
            ->post('/import/preview', ['file' => $this->jsonFile(['format' => 'other/1', 'entries' => []])])
            ->assertRedirect(route('diary.import'))
            ->assertSessionHasErrors('file');
    }

    public function test_stats_are_scoped_and_calculate_streaks(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        foreach (['2026-01-01', '2026-01-02', '2026-01-03'] as $date) {
            Entry::create([
                'user_id' => $user->id,
                'entry_date' => $date,
                'title' => '连续日记',
                'body' => '一二三四五',
                'status' => 'published',
            ]);
        }

        Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2025-05-01',
            'title' => '旧年日记',
            'body' => '旧年正文',
            'status' => 'published',
        ]);

        Entry::create([
            'user_id' => $other->id,
            'entry_date' => '2026-01-04',
            'title' => '别人的日记',
            'body' => '不应被统计',
            'status' => 'published',
        ]);

        $this->actingAs($user)->get('/stats?year=2026')
            ->assertOk()
            ->assertSee('2026 年热力图')
            ->assertSee('3', false)
            ->assertDontSee('不应被统计');

        $this->actingAs($user)->get('/stats?year=2025')->assertSee('2025 年热力图')->assertDontSee('2026 年热力图');
        $this->actingAs($user)->get('/stats?year=2026-99')->assertNotFound();
    }

    private function payload(array $entries): array
    {
        return [
            'format' => 'private-diary/1',
            'exported_at' => now()->toIso8601String(),
            'entries' => $entries,
        ];
    }

    private function jsonFile(array $payload): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'diary.json',
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
