@extends('layouts.app')

@section('title', '恢复码 · '.config('app.name'))

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $action }}</h1>
        <p class="muted">请现在抄写或下载保存。这些恢复码只显示一次。</p>
    </div>
    <a href="{{ route('security.index') }}">返回安全设置</a>
</div>

<section class="export-card">
    <ul class="recovery-codes">
        @foreach ($codes as $code)
            <li><code>{{ \App\Services\TwoFactorAuth::formatRecoveryCode($code) }}</code></li>
        @endforeach
    </ul>
    <div class="notice">每枚恢复码只能使用一次；丢失验证器时可用它登录。不要保存在与日记相同的明文位置。</div>
</section>
@endsection
