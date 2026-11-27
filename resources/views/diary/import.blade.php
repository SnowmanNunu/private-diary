@extends('layouts.app')

@section('title', '导入 · '.config('app.name'))

@section('content')
<div class="page-head">
    <div>
        <h1>导入</h1>
        <p class="muted">从本系统导出的 JSON 或 ZIP 恢复日记。当前共 {{ $entryCount }} 篇。</p>
    </div>
    <a href="{{ route('diary.export') }}">去导出</a>
</div>

@if ($errors->any())
    <div class="errors">
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif

<div class="export-grid">
    <section class="export-card">
        <div class="card-icon">JSON</div>
        <h2>JSON 数据</h2>
        <p class="muted">恢复日记正文、状态与标签。文件不含图片二进制，已有图片链接会保留原样。</p>
    </section>

    <section class="export-card">
        <div class="card-icon">ZIP</div>
        <h2>Markdown 压缩包</h2>
        <p class="muted">完整恢复日记与图片附件，并把正文中的图片链接指向新导入的图片。</p>
    </section>
</div>

<section class="export-card import-upload">
    <h2>选择文件</h2>
    <p class="muted">单个文件不超过 20 MB。先预览再确认，不会覆盖任何内容直到你点击「确认导入」。</p>
    <form method="POST" action="{{ route('diary.import.preview') }}" enctype="multipart/form-data" class="form-stack">
        @csrf
        <label>
            <span>导入文件（.json 或 .zip）</span>
            <input type="file" name="file" accept=".json,.zip,application/json,application/zip" required>
        </label>
        <div><button type="submit" class="primary">预览导入</button></div>
    </form>
</section>

@if ($preview)
    <section class="export-card import-preview">
        <h2>预览结果</h2>
        <p class="muted">
            来源 {{ $preview['source'] }} · 共 {{ $preview['total'] }} 篇 ·
            新增 {{ $preview['new'] }} 篇 · 冲突 {{ $preview['conflict'] }} 篇 ·
            附件 {{ $preview['attachments'] }} 个（可恢复 {{ $preview['images'] }} 张）
        </p>

        @if ($preview['warnings'] !== [])
            <div class="notice">
                @foreach ($preview['warnings'] as $warning)
                    <p>{{ $warning }}</p>
                @endforeach
            </div>
        @endif

        @if ($preview['entries'] !== [])
            <ul class="import-list">
                @foreach ($preview['entries'] as $item)
                    <li>
                        <span class="import-date">{{ $item['date'] }}</span>
                        <span class="import-title">{{ $item['title'] ?: '无标题' }}</span>
                        <span class="tag">{{ $item['action'] === 'new' ? '新增' : '冲突' }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($preview['total'] > count($preview['entries']))
                <p class="muted">仅显示前 {{ count($preview['entries']) }} 篇。</p>
            @endif
        @endif

        <form method="POST" action="{{ route('diary.import.store') }}" class="form-stack">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label>
                <span>遇到相同日期时</span>
                <select name="strategy">
                    <option value="skip">跳过已有日记（推荐，可重复执行）</option>
                    <option value="overwrite">用导入内容覆盖（会替换正文、标签和图片）</option>
                </select>
            </label>
            <div>
                <button type="submit" class="primary">确认导入</button>
            </div>
        </form>
    </section>
@endif
@endsection
