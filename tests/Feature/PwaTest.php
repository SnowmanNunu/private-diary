<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_and_service_worker_are_public(): void
    {
        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json');
        $this->assertStringContainsString('"start_url": "/diary"', file_get_contents(public_path('manifest.webmanifest')));
        $this->assertStringContainsString('icon-maskable-512.png', file_get_contents(public_path('manifest.webmanifest')));

        $this->get('/sw.js')
            ->assertOk()
            ->assertHeader('Service-Worker-Allowed', '/');
        $this->assertStringContainsString('private-diary-shell-v2', file_get_contents(public_path('sw.js')));
        $this->assertStringContainsString('SHELL.includes(url.pathname)', file_get_contents(public_path('sw.js')));
    }

    public function test_offline_editor_is_a_static_shell_without_private_data(): void
    {
        $this->get('/offline')
            ->assertOk()
            ->assertSee('离线写作')
            ->assertSee('draft-items')
            ->assertDontSee('csrf-token');
    }

    public function test_authenticated_pages_load_the_offline_shell(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/diary/create')
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('js/offline-drafts.js', false)
            ->assertSee('id="pwa-status"', false)
            ->assertSee('离线写作');
    }

    public function test_pwa_icons_are_local_files(): void
    {
        foreach (['icon.svg', 'icon-192.png', 'icon-512.png', 'icon-maskable-512.png'] as $icon) {
            $this->assertFileExists(public_path('icons/'.$icon));
        }
    }
}
