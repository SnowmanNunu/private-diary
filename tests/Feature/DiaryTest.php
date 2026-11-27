<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/diary')->assertRedirect(route('login'));
    }

    public function test_registration_route_does_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_user_can_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => bcrypt('a-long-password'),
        ]);

        $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'a-long-password',
        ])->assertRedirect(route('diary.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'user@example.com']);

        $this->from('/login')->post('/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ])->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_user_can_save_and_view_an_entry(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/diary/2026-11-27', [
                'title' => '第一天',
                'body' => '# 标题

正文内容。',
            ])
            ->assertRedirect(route('diary.show', '2026-11-27'));

        $this->assertDatabaseHas('entries', [
            'user_id' => $user->id,
            'entry_date' => '2026-11-27',
            'title' => '第一天',
            'status' => 'published',
        ]);

        $this->actingAs($user)
            ->get('/diary/2026-11-27')
            ->assertOk()
            ->assertSee('第一天')
            ->assertSee('<h1>标题</h1>', false);
    }

    public function test_autosave_creates_draft_and_returns_json(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/diary/2026-11-28', [
            'title' => '草稿',
            'body' => '还没写完',
            'autosave' => true,
        ]);

        $response->assertOk()->assertJsonPath('status', 'draft');

        $this->assertDatabaseHas('entries', [
            'user_id' => $user->id,
            'entry_date' => '2026-11-28',
            'status' => 'draft',
        ]);
    }

    public function test_editor_page_renders_for_a_date(): void
    {
        Entry::create([
            'user_id' => User::factory()->create()->id,
            'entry_date' => '2026-11-27',
            'title' => '已有日记',
            'body' => '内容',
            'status' => 'draft',
        ]);

        $user = User::latest('id')->first();

        $this->actingAs($user)
            ->get('/diary/create?date=2026-11-27')
            ->assertOk()
            ->assertSee('已有日记');
    }

    public function test_entry_is_scoped_to_its_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        Entry::create([
            'user_id' => $owner->id,
            'entry_date' => '2026-11-27',
            'body' => '私密内容',
            'status' => 'published',
        ]);

        $this->actingAs($other)->get('/diary/2026-11-27')->assertNotFound();
        $this->actingAs($other)->get('/diary')->assertDontSee('私密内容');
    }

    public function test_invalid_date_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/diary/2026-99-99')
            ->assertNotFound();
    }

    public function test_preview_strips_raw_html(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/diary/preview', ['body' => "<script>alert(1)</script>\n\n**安全**"])
            ->assertOk()
            ->assertJsonPath('html', "\n<p><strong>安全</strong></p>\n");
    }

    public function test_user_can_delete_an_entry(): void
    {
        $user = User::factory()->create();

        Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-11-27',
            'body' => '要删除的内容',
        ]);

        $this->actingAs($user)
            ->delete('/diary/2026-11-27')
            ->assertRedirect(route('diary.index'));

        $this->assertDatabaseMissing('entries', [
            'user_id' => $user->id,
            'entry_date' => '2026-11-27',
        ]);
    }
}
