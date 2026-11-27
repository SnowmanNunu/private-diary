<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Entry;
use App\Models\Tag;
use App\Models\User;
use App\Services\DiaryExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DiaryM3Test extends TestCase
{
    use RefreshDatabase;

    public function test_export_and_calendar_require_login(): void
    {
        $this->get('/export')->assertRedirect(route('login'));
        $this->get('/export/json')->assertRedirect(route('login'));
        $this->get('/export/markdown')->assertRedirect(route('login'));
        $this->get('/calendar')->assertRedirect(route('login'));
    }

    public function test_json_export_contains_only_own_entries(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-11-27',
            'title' => '我的日记',
            'body' => '只导出这一篇',
            'status' => 'published',
        ]);

        Entry::create([
            'user_id' => $other->id,
            'entry_date' => '2026-11-28',
            'title' => '别人的日记',
            'body' => '别人的秘密',
            'status' => 'published',
        ]);

        $response = $this->actingAs($user)->get('/export/json')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json; charset=utf-8');

        $payload = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('private-diary/1', $payload['format']);
        $this->assertCount(1, $payload['entries']);
        $this->assertSame('2026-11-27', $payload['entries'][0]['date']);
        $this->assertSame('我的日记', $payload['entries'][0]['title']);
        $this->assertStringNotContainsString('别人的秘密', $response->streamedContent());
    }

    public function test_markdown_zip_export_includes_images_tags_and_relative_links(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $entry = Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-11-27',
            'title' => '搬家日记',
            'body' => '![狗](/attachments/1)',
            'status' => 'published',
        ]);

        $tag = Tag::create(['user_id' => $user->id, 'name' => '搬家']);
        $entry->tags()->attach($tag);

        Storage::disk('local')->put('attachments/x.jpg', 'fake-image-bytes');

        $attachment = Attachment::create([
            'user_id' => $user->id,
            'entry_id' => $entry->id,
            'path' => 'attachments/x.jpg',
            'original_name' => '海边.jpg',
            'mime' => 'image/jpeg',
            'size' => 15,
        ]);

        $entry->body = '![狗](/attachments/'.$attachment->id.')';
        $entry->save();

        $response = $this->actingAs($user)->get('/export/markdown')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip');

        $this->assertStringContainsString(
            'attachment; filename=',
            $response->headers->get('Content-Disposition') ?? '',
        );

        $path = tempnam(sys_get_temp_dir(), 'diary-test-');
        $this->assertNotFalse($path);
        $this->app->make(DiaryExporter::class)->writeMarkdownZip($path);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);

        $markdown = $zip->getFromName('entries/2026/2026-11-27.md');
        $this->assertIsString($markdown);
        $this->assertStringContainsString('- 标签：搬家', $markdown);
        $this->assertStringContainsString('![狗](../attachments/'.$attachment->id.'-海边.jpg)', $markdown);
        $this->assertSame(
            'fake-image-bytes',
            $zip->getFromName('attachments/'.$attachment->id.'-海边.jpg'),
        );

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('2026-11-27', $manifest['entries'][0]['date']);
        $this->assertSame(['搬家'], $manifest['entries'][0]['tags']);

        $zip->close();
        unlink($path);
    }

    public function test_calendar_shows_entries_for_selected_month_only(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-11-10',
            'title' => '十一月的日记',
            'status' => 'published',
        ]);

        Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-12-05',
            'title' => '十二月的日记',
            'status' => 'published',
        ]);

        Entry::create([
            'user_id' => $other->id,
            'entry_date' => '2026-11-11',
            'title' => '别人的日记',
            'status' => 'published',
        ]);

        $this->actingAs($user)->get('/calendar?month=2026-11')
            ->assertOk()
            ->assertSee('2026 年 11 月')
            ->assertSee(route('diary.show', '2026-11-10'), false)
            ->assertSee('十一月的日记')
            ->assertDontSee('十二月的日记')
            ->assertDontSee('别人的日记')
            ->assertSee(route('diary.calendar', ['month' => '2026-12']), false);

        $this->actingAs($user)->get('/calendar?month=2026-12')
            ->assertOk()
            ->assertSee('十二月的日记');
    }

    public function test_calendar_rejects_invalid_month(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/calendar?month=2026-13')
            ->assertNotFound();
    }

    public function test_last_year_today_is_shown_on_index(): void
    {
        $user = User::factory()->create();
        $lastYear = now()->subYear()->toDateString();

        Entry::create([
            'user_id' => $user->id,
            'entry_date' => $lastYear,
            'title' => '去年的今天',
            'body' => '一年前的记录',
            'status' => 'published',
        ]);

        $this->actingAs($user)->get('/diary')
            ->assertOk()
            ->assertSee('去年今日')
            ->assertSee('去年的今天');
    }

    public function test_reading_page_shows_same_day_last_year(): void
    {
        $user = User::factory()->create();

        Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2025-05-01',
            'title' => '去年这一天',
            'body' => '去年同日的记录',
            'status' => 'published',
        ]);

        Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-05-01',
            'title' => '今年这一天',
            'body' => '今天的记录',
            'status' => 'published',
        ]);

        $this->actingAs($user)->get('/diary/2026-05-01')
            ->assertOk()
            ->assertSee('去年同日')
            ->assertSee('去年这一天');
    }

    public function test_last_year_review_is_scoped_to_its_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $lastYear = now()->subYear()->toDateString();

        Entry::create([
            'user_id' => $owner->id,
            'entry_date' => $lastYear,
            'title' => '不容窥探的回忆',
            'body' => '私密回顾',
            'status' => 'published',
        ]);

        $this->actingAs($other)->get('/diary')
            ->assertOk()
            ->assertDontSee('不容窥探的回忆');
    }
}
