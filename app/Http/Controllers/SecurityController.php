<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

class SecurityController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        return view('security.index', [
            'user' => $user,
            'recoveryCount' => count($user->two_factor_recovery_codes ?? []),
        ]);
    }
}
