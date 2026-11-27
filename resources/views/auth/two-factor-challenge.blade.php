@extends('layouts.app')

@section('title', '二重验证 · '.config('app.name'))

@section('content')
<div class="auth-card">
    <div class="auth-brand">
        <span class="brand-mark">日</span>
        <span class="brand-name">{{ config('app.name') }}</span>
    </div>

    <h1>二重验证</h1>
    <p class="muted">输入验证器中的 6 位验证码，或使用一枚未用过的恢复码。</p>

    @if ($errors->any())
        <div class="errors">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('two-factor.challenge.store') }}" class="form-stack">
        @csrf
        <label>
            <span>验证码 / 恢复码</span>
            <input type="text" name="code" value="{{ old('code') }}" inputmode="text"
                   autocomplete="one-time-code" autocapitalize="characters" required autofocus>
        </label>
        <button type="submit" class="primary">验证并登录</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="challenge-cancel">
        @csrf
        <button type="submit" class="link-button">取消登录</button>
    </form>
</div>
@endsection
