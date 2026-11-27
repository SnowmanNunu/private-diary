<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DiaryV03Test extends TestCase
{
    use RefreshDatabase;

    public function test_mood_and_weather_are_saved_and_displayed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/diary/2026-05-01', [
            'title' => '好日子',
            'body' => '去公园散步',
            'mood' => 'happy',
            'weather' => 'sunny',
        ])->assertRedirect();

        $this->assertDatabaseHas('entries', [
            'user_id' => $user->id,
            'entry_date' => '2026-05-01',
            'mood' => 'happy',
            'weather' => 'sunny',
        ]);

        $this->actingAs($user)->get('/diary/2026-05-01')
            ->assertOk()
            ->assertSee('开心')
            ->assertSee('晴');

        $this->actingAs($user)->get('/diary/create?date=2026-05-01')
            ->assertOk()
            ->assertSee('name="mood"', false)
            ->assertSee('name="weather"', false)
            ->assertSee('开心')
            ->assertSee('晴');
    }

    public function test_autosave_updates_mood_and_weather(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/diary/2026-05-02', [
            'body' => '内容',
            'mood' => 'calm',
            'weather' => 'rainy',
            'autosave' => true,
        ])->assertOk();

        $entry = Entry::where('user_id', $user->id)->where('entry_date', '2026-05-02')->firstOrFail();
        $this->assertSame('calm', $entry->mood);
        $this->assertSame('rainy', $entry->weather);
        $this->assertSame('draft', $entry->status);
    }

    public function test_invalid_mood_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/diary/2026-05-03', [
            'title' => '无效心情',
            'mood' => 'unknown',
        ])->assertSessionHasErrors('mood');

        $this->assertSame(0, Entry::where('user_id', $user->id)->count());
    }

    public function test_filters_combine_mood_weather_and_date_range(): void
    {
        $user = User::factory()->create();

        foreach ([
            ['2026-05-01', 'happy', 'sunny', '第一篇'],
            ['2026-05-02', 'sad', 'rainy', '第二篇'],
            ['2026-05-03', 'happy', 'rainy', '第三篇'],
        ] as [$date, $mood, $weather, $title]) {
            Entry::create([
                'user_id' => $user->id,
                'entry_date' => $date,
                'title' => $title,
                'body' => '正文',
                'mood' => $mood,
                'weather' => $weather,
                'status' => 'published',
            ]);
        }

        $this->actingAs($user)->get('/diary?mood=happy')
            ->assertOk()
            ->assertSee('第一篇')
            ->assertSee('第三篇')
            ->assertDontSee('第二篇');

        $this->actingAs($user)->get('/diary?weather=rainy')
            ->assertOk()
            ->assertSee('第二篇')
            ->assertSee('第三篇')
            ->assertDontSee('第一篇');

        $this->actingAs($user)->get('/diary?from=2026-05-02&to=2026-05-02')
            ->assertOk()
            ->assertSee('第二篇')
            ->assertDontSee('第一篇')
            ->assertDontSee('第三篇');

        $this->actingAs($user)->get('/diary?mood=happy&weather=rainy&from=2026-05-02&to=2026-05-04')
            ->assertOk()
            ->assertSee('第三篇')
            ->assertDontSee('第一篇')
            ->assertDontSee('第二篇');
    }

    public function test_invalid_date_range_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/diary?from=2026-99-99')->assertSessionHasErrors('from');
        $this->actingAs($user)->get('/diary?from=2026-05-03&to=2026-05-01')->assertSessionHasErrors('to');
    }

    public function test_search_highlights_matches_and_escapes_html(): void
    {
        $user = User::factory()->create();

        Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-05-05',
            'title' => '搬家 <img src=x onerror=alert(1)>',
            'body' => '今天终于搬家了，累但开心',
            'status' => 'published',
        ]);

        $this->actingAs($user)->get('/diary?q='.urlencode('搬家'))
            ->assertOk()
            ->assertSee('<mark>搬家</mark>', false)
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertDontSee('<img src=x', false);
    }

    public function test_filters_are_scoped_to_their_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        Entry::create([
            'user_id' => $owner->id,
            'entry_date' => '2026-05-06',
            'title' => '不能给别人看到的心情',
            'body' => '正文',
            'mood' => 'happy',
            'status' => 'published',
        ]);

        $this->actingAs($other)->get('/diary?mood=happy')
            ->assertOk()
            ->assertDontSee('不能给别人看到的心情');
    }

    public function test_export_and_import_preserve_mood_and_weather(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-05-07',
            'title' => '雨后',
            'body' => '空气很好',
            'mood' => 'calm',
            'weather' => 'cloudy',
            'status' => 'published',
        ]);

        $json = $this->actingAs($user)->get('/export/json')->streamedContent();
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('private-diary/2', $payload['format']);
        $this->assertSame('calm', $payload['entries'][0]['mood']);
        $this->assertSame('cloudy', $payload['entries'][0]['weather']);

        $other = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('diary.json', $json);

        $this->actingAs($other)->post('/import/preview', ['file' => $file])
            ->assertRedirect(route('diary.import'));
        $this->actingAs($other)->post('/import', [
            'token' => session('import_token'),
            'strategy' => 'skip',
        ])->assertRedirect(route('diary.index'));

        $imported = Entry::where('user_id', $other->id)->where('entry_date', '2026-05-07')->firstOrFail();
        $this->assertSame('calm', $imported->mood);
        $this->assertSame('cloudy', $imported->weather);
    }

    public function test_legacy_v1_export_can_still_be_imported(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $payload = [
            'format' => 'private-diary/1',
            'entries' => [[
                'date' => '2026-05-08',
                'title' => '旧格式',
                'body' => '没有心情字段',
                'status' => 'published',
                'tags' => [],
                'attachments' => [],
            ]],
        ];
        $file = UploadedFile::fake()->createWithContent(
            'old.json',
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $this->actingAs($user)->post('/import/preview', ['file' => $file])
            ->assertRedirect(route('diary.import'));
        $this->actingAs($user)->post('/import', [
            'token' => session('import_token'),
            'strategy' => 'skip',
        ])->assertRedirect(route('diary.index'));

        $imported = Entry::where('user_id', $user->id)->where('entry_date', '2026-05-08')->firstOrFail();
        $this->assertNull($imported->mood);
        $this->assertNull($imported->weather);
    }
}
