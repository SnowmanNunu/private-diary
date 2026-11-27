@extends('layouts.app')

@section('title', '启用二重验证 · '.config('app.name'))

@section('content')
<div class="page-head">
    <div>
        <h1>启用二重验证</h1>
        <p class="muted">用验证器 App 扫描二维码，输入生成的 6 位验证码完成启用。</p>
    </div>
    <a href="{{ route('security.index') }}">返回安全设置</a>
</div>

<div class="security-grid">
    <section class="export-card setup-qr">
        <h2>1. 扫描二维码</h2>
        <div class="qr-code">{!! $qrCode !!}</div>
        <p class="muted">无法扫描？手动输入密钥：<code>{{ $secret }}</code></p>
    </section>

    <section class="export-card">
        <h2>2. 输入验证码</h2>
        @if ($errors->any())
            <div class="errors">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('two-factor.enable') }}" class="form-stack">
            @csrf
            <label>
                <span>6 位验证码</span>
                <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}"
                       autocomplete="one-time-code" maxlength="6" required autofocus>
            </label>
            <button type="submit" class="primary">确认启用</button>
        </form>
        <p class="muted setup-note">启用成功后会显示一次性恢复码，请先保存再关闭页面。</p>
    </section>
</div>
@endsection
