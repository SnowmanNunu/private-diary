<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Models\User;

class SessionController extends Controller
{
    public function showLogin()
    {
        if (session()->has('two_factor_pending.id')) {
            return redirect()->route('two-factor.challenge');
        }

        return view('auth.login', [
            'hasUsers' => User::exists(),
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $remember = $request->boolean('remember');
            $user = Auth::user();

            if ($user->hasTwoFactorEnabled()) {
                $intended = $request->session()->get('url.intended', route('diary.index'));

                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $request->session()->put('two_factor_pending', [
                    'id' => $user->id,
                    'remember' => $remember,
                    'intended' => $intended,
                ]);

                return redirect()->route('two-factor.challenge');
            }

            $request->session()->regenerate();
            $request->session()->put('two_factor_verified', true);

            return redirect()->intended(route('diary.index'));
        }

        throw ValidationException::withMessages([
            'email' => '邮箱或密码不正确',
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
