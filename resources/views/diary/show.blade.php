@extends('layouts.app')

@section('title', ($entry->title ?: '日记').' · '.config('app.name'))

@section('content')
<article class="reader">
    <header class="reader-head">
        <div>
            <p class="reader-date">{{ $entry->entry_date->format('Y 年 m 月 d 日') }}</p>
            <h1>{{ $entry->title ?: '无标题' }}</h1>
        </div>
        <div class="reader-actions">
            <a href="{{ route('diary.create', ['date' => $entry->entry_date->format('Y-m-d')]) }}">编辑</a>
            <a href="{{ route('diary.index') }}">返回列表</a>
        </div>
    </header>

    <div class="tag-list reader-tags">
        @foreach ($entry->tags as $tag)
            <a class="tag" href="{{ route('diary.index', ['tag' => $tag->name]) }}">{{ $tag->name }}</a>
        @endforeach
        @if ($entry->mood)
            <span class="mood-chip mood-{{ $entry->mood }}">{!! $icons->mood($entry->mood) !!}<span>{{ config('diary.moods.'.$entry->mood, $entry->mood) }}</span></span>
        @endif
        @if ($entry->weather)
            <span class="weather-chip">{!! $icons->weather($entry->weather) !!}<span>{{ config('diary.weather.'.$entry->weather, $entry->weather) }}</span></span>
        @endif
        @if ($entry->status === 'draft')
            <span class="tag">草稿</span>
        @endif
    </div>

    @if ($lastYearEntry)
        <aside class="review-card">
            <p class="muted">
                {{ $entry->entry_date->format('Y-m-d') === now()->toDateString() ? '去年今日' : '去年同日' }}
                · {{ $lastYearEntry->entry_date->format('Y 年 m 月 d 日') }}
            </p>
            <a class="review-title" href="{{ route('diary.show', $lastYearEntry->entry_date->format('Y-m-d')) }}">
                {{ $lastYearEntry->title ?: '无标题' }}
            </a>
            <p class="review-excerpt">{{ \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($lastYearEntry->body ?? ''))), 120) }}</p>
        </aside>
    @endif

    <div class="prose">{!! $html !!}</div>

    @if ($entry->attachments->isNotEmpty())
        <section class="reader-attachments">
            <h2>附件</h2>
            <div class="attachment-list">
                @foreach ($entry->attachments as $attachment)
                    <div class="attachment-item {{ $attachment->isImage() ? '' : 'attachment-file' }}">
                        @if ($attachment->isImage())
                            <a href="{{ route('attachments.show', $attachment) }}" target="_blank" rel="noopener">
                                <img src="{{ route('attachments.show', $attachment) }}" alt="{{ $attachment->original_name }}">
                            </a>
                            <div class="attachment-caption">{{ $attachment->original_name }}</div>
                        @else
                            <a class="file-link" href="{{ route('attachments.show', $attachment) }}" target="_blank" rel="noopener">
                                <span class="file-icon">{{ $attachment->badge() }}</span>
                                <span class="file-name">{{ $attachment->original_name }}</span>
                                <span class="file-meta">{{ $attachment->sizeLabel() }}</span>
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <footer class="reader-footer">
        <form method="POST" action="{{ route('diary.destroy', $entry->entry_date->format('Y-m-d')) }}"
              onsubmit="return confirm('确定删除这一天的日记？');">
            @csrf
            @method('DELETE')
            <button type="submit" class="danger">删除这篇</button>
        </form>
    </footer>
</article>
@endsection
