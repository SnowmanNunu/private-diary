@extends('layouts.app')

@section('title', '登录 · '.config('app.name'))

@section('content')
<div class="auth-card">
    <div class="auth-brand">
        <span class="brand-mark">日</span>
        <span class="brand-name">{{ config('app.name') }}</span>
    </div>

    <h1>登录</h1>
    <p class="muted">仅你自己可以访问这个日记。</p>

    @if (! $hasUsers)
        <div class="notice">
            还没有用户。先在服务器上运行：
            <code>php artisan diary:user</code>
        </div>
    @endif

    @if ($errors->any())
        <div class="errors">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="form-stack">
        @csrf
        <label>
            <span>邮箱</span>
            <input type="email" name="email" value="{{ old('email') }}" required autocomplete="username" autofocus>
        </label>
        <label>
            <span>密码</span>
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <label class="checkbox">
            <input type="checkbox" name="remember" value="1">
            <span>记住我</span>
        </label>
        <button type="submit" class="primary">登录</button>
    </form>
</div>
@endsection
