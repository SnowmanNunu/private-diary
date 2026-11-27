<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_pages_require_login(): void
    {
        $this->get('/security')->assertRedirect(route('login'));
        $this->get('/security/two-factor/setup')->assertRedirect(route('login'));
        $this->get('/two-factor/challenge')->assertRedirect(route('login'));
        $this->post('/two-factor/challenge', ['code' => '123456'])->assertRedirect(route('login'));
    }

    public function test_user_can_enable_two_factor_with_qr_and_recovery_codes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/security')
            ->assertOk()
            ->assertSee('二重验证未启用')
            ->assertSee('开始启用');

        $this->actingAs($user)->get('/security/two-factor/setup')
            ->assertOk()
            ->assertSee('<svg', false);

        $secret = session('two_factor_setup_secret');
        $this->assertIsString($secret);

        $code = (new Google2FA())->getCurrentOtp($secret);

        $this->actingAs($user)->post('/security/two-factor/enable', ['code' => $code])
            ->assertOk()
            ->assertSee('启用成功');

        $user->refresh();

        $this->assertTrue($user->hasTwoFactorEnabled());
        $this->assertNotSame($secret, $user->getRawOriginal('two_factor_secret'));
        $this->assertCount(8, $user->two_factor_recovery_codes ?? []);
        $this->assertTrue(session('two_factor_verified'));
    }

    public function test_invalid_code_cannot_enable_two_factor(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/security/two-factor/setup')->assertOk();
        $this->actingAs($user)->post('/security/two-factor/enable', ['code' => 'abcdef'])
            ->assertSessionHasErrors('code');

        $user->refresh();
        $this->assertFalse($user->hasTwoFactorEnabled());
    }

    public function test_login_requires_totp_challenge_before_authenticating(): void
    {
        $user = User::factory()->create();
        $secret = $this->enableTwoFactor($user);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
        $this->get('/two-factor/challenge')->assertOk()->assertSee('二重验证');

        $this->post('/two-factor/challenge', ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/two-factor/challenge', [
            'code' => (new Google2FA())->getCurrentOtp($secret),
        ])->assertRedirect(route('diary.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(session('two_factor_verified'));
    }

    public function test_recovery_code_can_log_in_only_once(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);
        $codes = $user->fresh()->two_factor_recovery_codes;
        $raw = app(TwoFactorAuth::class)->generateRecoveryCodes();

        $user->two_factor_recovery_codes = app(TwoFactorAuth::class)->hashRecoveryCodes($raw);
        $user->save();

        $formatted = TwoFactorAuth::formatRecoveryCode($raw[0]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.challenge'));

        $this->post('/two-factor/challenge', ['code' => $formatted])
            ->assertRedirect(route('diary.index'));
        $this->assertAuthenticatedAs($user);
        $this->assertCount(7, $user->fresh()->two_factor_recovery_codes);

        $this->post('/logout');
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.challenge'));

        $this->from(route('two-factor.challenge'))
            ->post('/two-factor/challenge', ['code' => $formatted])
            ->assertRedirect(route('two-factor.challenge'))
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_authenticated_session_cannot_bypass_two_factor(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->actingAs($user)->get('/diary')
            ->assertRedirect(route('login'));
        $this->assertGuest();

        $this->withSession(['two_factor_verified' => true])
            ->actingAs($user)
            ->get('/diary')
            ->assertOk();
    }

    public function test_two_factor_can_be_disabled_with_password_and_code(): void
    {
        $user = User::factory()->create();
        $secret = $this->enableTwoFactor($user);

        $this->withSession(['two_factor_verified' => true])->actingAs($user)
            ->post('/security/two-factor/disable', [
                'password' => 'wrong-password',
                'code' => (new Google2FA())->getCurrentOtp($secret),
            ])->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        $this->withSession(['two_factor_verified' => true])->actingAs($user)
            ->post('/security/two-factor/disable', [
                'password' => 'password',
                'code' => (new Google2FA())->getCurrentOtp($secret),
            ])->assertRedirect(route('security.index'));

        $user->refresh();
        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
    }

    public function test_recovery_codes_can_be_regenerated(): void
    {
        $user = User::factory()->create();
        $secret = $this->enableTwoFactor($user);
        $oldHashes = $user->fresh()->two_factor_recovery_codes;

        $this->withSession(['two_factor_verified' => true])->actingAs($user)
            ->post('/security/two-factor/recovery-codes', [
                'password' => 'password',
                'code' => (new Google2FA())->getCurrentOtp($secret),
            ])
            ->assertOk()
            ->assertSee('新的恢复码已生成');

        $newHashes = $user->fresh()->two_factor_recovery_codes;
        $this->assertCount(8, $newHashes);
        $this->assertNotSame($oldHashes, $newHashes);
    }

    private function enableTwoFactor(User $user): string
    {
        $service = app(TwoFactorAuth::class);
        $secret = $service->generateSecret();
        $service->enable($user, $secret);

        return $secret;
    }
}
