@extends('layouts.app')

@section('title', '安全 · '.config('app.name'))

@section('content')
<div class="page-head">
    <div>
        <h1>安全</h1>
        <p class="muted">管理登录保护与恢复码。</p>
    </div>
</div>

@if ($user->hasTwoFactorEnabled())
    <section class="export-card">
        <div class="card-icon">2FA</div>
        <h2>二重验证已启用</h2>
        <p class="muted">启用时间：{{ $user->two_factor_enabled_at?->format('Y-m-d H:i') }} · 剩余恢复码 {{ $recoveryCount }} 枚</p>
        @if ($recoveryCount === 0)
            <p class="errors">恢复码已用完，请立即重新生成，否则将无法在丢失验证器时登录。</p>
        @endif
    </section>

    <div class="security-grid">
        <section class="export-card">
            <div class="card-icon">KEY</div>
            <h2>重新生成恢复码</h2>
            <p class="muted">旧恢复码会立即失效。需要输入密码与当前验证码。</p>
            @include('security.partials.sensitive-form', ['action' => route('two-factor.recovery-codes'), 'button' => '生成新恢复码'])
        </section>

        <section class="export-card">
            <div class="card-icon">OFF</div>
            <h2>关闭二重验证</h2>
            <p class="muted">关闭后登录只需密码。需要输入密码与当前验证码。</p>
            @include('security.partials.sensitive-form', ['action' => route('two-factor.disable'), 'button' => '关闭二重验证'])
        </section>
    </div>
@else
    <section class="export-card">
        <div class="card-icon">2FA</div>
        <h2>二重验证未启用</h2>
        <p class="muted">使用验证器 App（如 Google Authenticator、1Password）生成 6 位动态码。启用时会生成一次性恢复码。</p>
        <a class="primary" href="{{ route('two-factor.setup') }}">开始启用</a>
    </section>
@endif
@endsection
