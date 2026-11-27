<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineSyncDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 会话过期时前端发的 JSON 保存请求会被 auth 中间件拒绝，而不是返回成功 JSON。
     * 若前端不区分这种情况，就会只显示「同步失败」，用户无从排查。
     */
    public function test_expired_session_returns_401_instead_of_a_saved_response(): void
    {
        $this->postJson('/diary/2026-11-27', [
            'title' => '离线草稿',
            'body' => '内容',
            'autosave' => true,
            'revision' => 0,
        ])->assertStatus(401)->assertJson(['message' => 'Unauthenticated.']);
    }

    /**
     * 启用二重验证但本次会话未验证时，中间件直接重定向到登录页（不理会 Accept: application/json）。
     * fetch 默认跟随重定向会拿到 200 的 HTML，前端必须检测 response.redirected。
     */
    public function test_unverified_two_factor_redirects_a_json_save(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['two_factor_enabled_at' => now()])->save();

        $this->actingAs($user)->postJson('/diary/2026-11-27', [
            'title' => '离线草稿',
            'body' => '内容',
            'autosave' => true,
            'revision' => 0,
        ])->assertRedirect(route('login'));
    }

    public function test_offline_sync_detects_auth_failures_and_reports_a_reason(): void
    {
        $js = file_get_contents(public_path('js/offline-drafts.js'));
        $this->assertStringContainsString('response.redirected', $js);
        $this->assertStringContainsString("'AUTH_REQUIRED'", $js);
        $this->assertStringContainsString("'SESSION_EXPIRED'", $js);
        $this->assertStringContainsString('reasons:', $js);

        $view = file_get_contents(resource_path('views/offline.blade.php'));
        $this->assertStringContainsString('reasonsText', $view);
        $this->assertStringContainsString('relogin-link', $view);
        $this->assertStringContainsString('登录状态已失效', $view);
        $this->assertStringContainsString('内容未通过校验', $view);
        $this->assertStringContainsString('草稿仍在本机', $view);
    }
}
