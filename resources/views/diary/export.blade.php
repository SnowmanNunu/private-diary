@extends('layouts.app')

@section('title', '导出 · '.config('app.name'))

@section('content')
<div class="page-head">
    <div>
        <h1>导出</h1>
        <p class="muted">当前共 {{ $entryCount }} 篇日记。导出文件包含全部正文、标签和附件，仅在你登录后可下载。</p>
    </div>
    <a href="{{ route('diary.import') }}">导入数据</a>
</div>

<div class="export-grid">
    <section class="export-card">
        <div class="card-icon">ZIP</div>
        <h2>Markdown 压缩包</h2>
        <p class="muted">按年份分目录，每篇一个 Markdown 文件；图片放在 <code>attachments/</code>，正文中的图片链接会改为相对路径。另附 <code>manifest.json</code> 记录元数据。</p>
        <a class="primary" href="{{ route('diary.export.markdown') }}">下载 ZIP</a>
    </section>

    <section class="export-card">
        <div class="card-icon">JSON</div>
        <h2>JSON 数据</h2>
        <p class="muted">结构化保存日期、标题、正文、状态、标签和附件信息，方便以后迁移或做二次处理。图片二进制不包含在 JSON 中。</p>
        <a class="primary" href="{{ route('diary.export.json') }}">下载 JSON</a>
    </section>
</div>

<div class="notice export-note">备份包建议加密后再上传到外部存储；同时保留 ZIP 与 JSON，可到「导入数据」完整恢复。</div>
@endsection
