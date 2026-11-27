<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Entry;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DiaryM2Test extends TestCase
{
    use RefreshDatabase;

    public function test_tags_are_synced_and_can_filter_entries(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/diary/2026-11-27', [
            'title' => '旅行日记',
            'body' => '今天去了海边',
            'tags' => '生活, 旅行',
        ])->assertRedirect();

        $this->actingAs($user)->post('/diary/2026-11-28', [
            'title' => '工作',
            'body' => '修了一些 bug',
            'tags' => '工作',
        ])->assertRedirect();

        $this->assertDatabaseHas('tags', ['user_id' => $user->id, 'name' => '旅行']);
        $this->assertDatabaseHas('tags', ['user_id' => $user->id, 'name' => '工作']);

        $this->actingAs($user)->get('/diary?tag='.urlencode('旅行'))
            ->assertOk()
            ->assertSee('旅行日记')
            ->assertDontSee('修了一些 bug');
    }

    public function test_autosave_updates_tags(): void
    {
        $user = User::factory()->create();

        $entry = Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-11-27',
            'body' => '内容',
            'status' => 'draft',
        ]);

        $tag = Tag::create(['user_id' => $user->id, 'name' => '旧标签']);
        $entry->tags()->attach($tag);

        $this->actingAs($user)->postJson('/diary/2026-11-27', [
            'body' => '内容',
            'tags' => '新标签',
            'autosave' => true,
        ])->assertOk();

        $this->assertTrue($entry->fresh()->tags->contains('name', '新标签'));
        $this->assertFalse($entry->fresh()->tags->contains('name', '旧标签'));
    }

    public function test_tags_are_scoped_to_their_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        Tag::create(['user_id' => $owner->id, 'name' => '私密标签']);

        $this->actingAs($other)->get('/diary?tag='.urlencode('私密标签'))
            ->assertOk()
            ->assertDontSee('私密标签');
    }

    public function test_full_text_search_finds_long_query(): void
    {
        $user = User::factory()->create();

        Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-11-27',
            'title' => '搬家记录',
            'body' => '哈士奇的搬家大作战很有趣',
            'status' => 'published',
        ]);

        Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-11-26',
            'title' => '其他',
            'body' => '今天下雨了',
            'status' => 'published',
        ]);

        $this->actingAs($user)->get('/diary?q='.urlencode('搬家大作战'))
            ->assertOk()
            ->assertSee('搬家记录')
            ->assertDontSee('今天下雨了');
    }

    public function test_short_query_search_uses_substring_match(): void
    {
        $user = User::factory()->create();

        Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-11-27',
            'title' => '周末计划',
            'body' => '去海边散步',
            'status' => 'published',
        ]);

        Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-11-26',
            'title' => '工作',
            'body' => '修了一些 bug',
            'status' => 'published',
        ]);

        $this->actingAs($user)->get('/diary?q=海边')
            ->assertOk()
            ->assertSee('周末计划')
            ->assertDontSee('修了一些 bug');
    }

    public function test_search_is_scoped_to_its_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        Entry::create([
            'user_id' => $owner->id,
            'entry_date' => '2026-11-27',
            'title' => '私密日记',
            'body' => '不能给别人看到的内容',
            'status' => 'published',
        ]);

        $this->actingAs($other)->get('/diary?q='.urlencode('不能给别人看到'))
            ->assertOk()
            ->assertDontSee('不能给别人看到的内容');
    }

    public function test_user_can_upload_and_view_private_image(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/diary/2026-11-27/attachments', [
            'images' => [UploadedFile::fake()->image('海边.jpg', 10, 10)],
        ]);

        $response->assertCreated()->assertJsonPath('ok', true);

        $attachment = Attachment::firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);

        $this->actingAs($user)
            ->get(route('attachments.show', $attachment))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_image_cannot_be_viewed_or_deleted_by_another_user(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $other = User::factory()->create();

        $attachment = Attachment::create([
            'user_id' => $owner->id,
            'entry_id' => Entry::create([
                'user_id' => $owner->id,
                'entry_date' => '2026-11-27',
            ])->id,
            'path' => 'attachments/x.jpg',
            'original_name' => 'x.jpg',
            'mime' => 'image/jpeg',
            'size' => 10,
        ]);

        $this->actingAs($other)->get(route('attachments.show', $attachment))->assertNotFound();
        $this->actingAs($other)->delete(route('attachments.destroy', $attachment))->assertNotFound();
    }

    public function test_user_can_delete_image(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)->post('/diary/2026-11-27/attachments', [
            'images' => [UploadedFile::fake()->image('海边.jpg', 10, 10)],
        ])->assertCreated();

        $attachment = Attachment::firstOrFail();

        $this->actingAs($user)
            ->delete(route('attachments.destroy', $attachment))
            ->assertOk()
            ->assertJsonPath('ok', true);

        Storage::disk('local')->assertMissing($attachment->path);
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    }

    public function test_invalid_image_is_rejected(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->actingAs($user)->post('/diary/2026-11-27/attachments', [
            'images' => [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')],
        ])->assertSessionHasErrors('images.*');
    }
}
