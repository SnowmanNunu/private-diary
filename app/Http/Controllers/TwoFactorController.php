<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TwoFactorAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function __construct(private TwoFactorAuth $twoFactor)
    {
    }

    public function showChallenge()
    {
        $user = $this->pendingUser();

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function verifyChallenge(Request $request)
    {
        $user = $this->pendingUser();

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        if (! $this->passesSecondFactor($user, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => '验证码或恢复码不正确',
            ]);
        }

        $pending = $request->session()->pull('two_factor_pending', []);
        $remember = (bool) ($pending['remember'] ?? false);

        $request->session()->regenerate();
        Auth::login($user, $remember);
        $request->session()->put('two_factor_verified', true);

        return redirect()->to($this->safeIntended($pending['intended'] ?? null));
    }

    public function setup()
    {
        $user = Auth::user();

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('security.index');
        }

        $secret = $this->twoFactor->generateSecret();
        session(['two_factor_setup_secret' => $secret]);

        return view('security.setup', [
            'secret' => $secret,
            'qrCode' => $this->twoFactor->qrCodeSvg(
                $this->twoFactor->provisioningUri($user, $secret),
            ),
        ]);
    }

    public function enable(Request $request)
    {
        $user = Auth::user();

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('security.index');
        }

        $secret = session('two_factor_setup_secret');

        if (! is_string($secret) || $secret === '') {
            return redirect()->route('two-factor.setup')->with('status', '设置会话已过期，请重新扫描二维码');
        }

        $data = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        if (! $this->twoFactor->verifySecret($secret, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => '验证码不正确，请检查时间是否同步',
            ]);
        }

        $codes = $this->twoFactor->enable($user, $secret);

        $request->session()->forget('two_factor_setup_secret');
        $request->session()->put('two_factor_verified', true);

        return view('security.recovery-codes', [
            'codes' => $codes,
            'action' => '启用成功',
        ]);
    }

    public function disable(Request $request)
    {
        $user = Auth::user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('security.index');
        }

        $data = $this->validateSensitiveAction($request);

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => '密码不正确',
            ]);
        }

        if (! $this->passesSecondFactor($user, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => '验证码或恢复码不正确',
            ]);
        }

        $this->twoFactor->disable($user);
        $request->session()->put('two_factor_verified', true);

        return redirect()->route('security.index')->with('status', '二重验证已关闭');
    }

    public function regenerateRecoveryCodes(Request $request)
    {
        $user = Auth::user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('security.index');
        }

        $data = $this->validateSensitiveAction($request);

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => '密码不正确',
            ]);
        }

        if (! $this->passesSecondFactor($user, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => '验证码或恢复码不正确',
            ]);
        }

        return view('security.recovery-codes', [
            'codes' => $this->twoFactor->regenerateRecoveryCodes($user),
            'action' => '新的恢复码已生成',
        ]);
    }

    private function validateSensitiveAction(Request $request): array
    {
        return $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:64'],
        ]);
    }

    private function passesSecondFactor(User $user, string $code): bool
    {
        return $this->twoFactor->verifyCode($user, $code)
            || $this->twoFactor->consumeRecoveryCode($user, $code);
    }

    private function pendingUser(): ?User
    {
        $id = session('two_factor_pending.id');

        return is_numeric($id) ? User::find((int) $id) : null;
    }

    private function safeIntended(mixed $url): string
    {
        if (is_string($url)
            && (Str::startsWith($url, url('/'))
                || (Str::startsWith($url, '/') && ! Str::startsWith($url, '//')))) {
            return $url;
        }

        return route('diary.index');
    }
}
