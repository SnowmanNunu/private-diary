@extends('layouts.app')

@section('title', '预览 · '.$attachment->original_name.' · '.config('app.name'))

@section('content')
@php($type = $preview['type'])
<article class="preview-page">
    <header class="preview-head">
        <div class="preview-heading">
            <p class="reader-date">附件预览</p>
            <h1>{{ $attachment->original_name }}</h1>
            <p class="muted">{{ $attachment->badge() }} · {{ $attachment->sizeLabel() }}</p>
        </div>
        <div class="preview-actions">
            <a href="{{ route('attachments.show', $attachment) }}" download>下载原文件</a>
            <a href="{{ route('diary.create', ['date' => $attachment->entry?->entry_date?->format('Y-m-d') ?? now()->toDateString()]) }}">返回编辑</a>
        </div>
    </header>

    @if (! empty($preview['notice']))
        <p class="preview-notice">{{ $preview['notice'] }}</p>
    @endif

    <div class="preview-body preview-{{ $type }}">
        @switch($type)
            @case('image')
                <img class="preview-image" src="{{ $preview['url'] }}" alt="{{ $attachment->original_name }}">
                @break

            @case('pdf')
                <iframe class="preview-frame" src="{{ $preview['url'] }}" title="{{ $attachment->original_name }}"></iframe>
                @break

            @case('text')
                @forelse ($preview['lines'] as $line)
                    <div class="preview-line">{{ $line }}</div>
                @empty
                    <p class="muted">这是一个空文件。</p>
                @endforelse
                @break

            @case('csv')
            @case('xlsx')
                <div class="preview-table-wrap">
                    <table class="preview-table">
                        @forelse ($preview['rows'] as $row)
                            <tr>
                                @foreach ($row as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td class="muted">这是一个空文件。</td></tr>
                        @endforelse
                    </table>
                </div>
                @break

            @case('docx')
                @forelse ($preview['paragraphs'] as $paragraph)
                    <p class="preview-paragraph">{{ $paragraph }}</p>
                @empty
                    <p class="muted">未能从文档中提取到文字，请下载后查看。</p>
                @endforelse
                @break

            @case('pptx')
                @forelse ($preview['slides'] as $slide)
                    <section class="preview-slide">
                        <h2>第 {{ $slide['number'] }} 页</h2>
                        @forelse ($slide['lines'] as $line)
                            <p>{{ $line }}</p>
                        @empty
                            <p class="muted">此页没有文字。</p>
                        @endforelse
                    </section>
                @empty
                    <p class="muted">未能从演示文稿中提取到文字，请下载后查看。</p>
                @endforelse
                @break

            @case('archive')
                <ul class="preview-list">
                    @forelse ($preview['names'] as $name)
                        <li>{{ $name }}</li>
                    @empty
                        <li class="muted">压缩包内没有文件。</li>
                    @endforelse
                </ul>
                @break

            @default
                <div class="preview-download">
                    <p>{{ $preview['notice'] ?? '这种格式暂不支持在线预览。' }}</p>
                    <a class="primary" href="{{ $preview['download_url'] }}" download>下载文件</a>
                </div>
        @endswitch
    </div>
</article>
@endsection
