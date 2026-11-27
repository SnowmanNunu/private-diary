@extends('layouts.app')

@section('title', '日记 · '.config('app.name'))

@section('content')
@php($weekdays = ['日', '一', '二', '三', '四', '五', '六'])
<div class="page-head">
    <div>
        <h1>日记</h1>
        <p class="muted">按日期记录，只属于你。</p>
    </div>
    <a class="primary" href="{{ route('diary.create') }}">写今天</a>
</div>

@if ($lastYearEntry)
    <section class="review-card">
        <p class="muted">去年今日 · {{ $lastYearEntry->entry_date->format('Y 年 m 月 d 日') }}</p>
        <a class="review-title" href="{{ route('diary.show', $lastYearEntry->entry_date->format('Y-m-d')) }}">
            {{ $lastYearEntry->title ?: '无标题' }}
        </a>
        <p class="review-excerpt">{{ \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($lastYearEntry->body ?? ''))), 120) }}</p>
    </section>
@endif

<form method="GET" action="{{ route('diary.index') }}" class="search-form">
    <input type="search" name="q" value="{{ $keyword }}" placeholder="搜索标题或正文（至少 3 字，短词也支持）">
    <button type="submit">搜索</button>
    @if ($keyword !== '')
        <a href="{{ route('diary.index', ['tag' => $activeTag]) }}">清除</a>
    @endif
</form>

<div class="filter-bar">
    <span class="muted">标签</span>
    <a class="tag-filter {{ $activeTag === '' ? 'active' : '' }}" href="{{ route('diary.index', ['q' => $keyword]) }}">全部</a>
    @forelse ($tags as $tag)
        <a class="tag-filter {{ $activeTag === $tag->name ? 'active' : '' }}"
           href="{{ route('diary.index', ['q' => $keyword, 'tag' => $tag->name]) }}">
            {{ $tag->name }} <span class="muted">{{ $tag->entries_count }}</span>
        </a>
    @empty
        <span class="muted">还没有标签</span>
    @endforelse
</div>

<form method="GET" action="{{ route('diary.create') }}" class="jump-form">
    <label for="jump-date">跳到日期</label>
    <input id="jump-date" type="date" name="date" value="{{ now()->toDateString() }}">
    <button type="submit">打开</button>
</form>

@if ($entries->isEmpty())
    <div class="empty">
        <p>没有找到日记。</p>
        <a href="{{ route('diary.create') }}">写下一篇</a>
    </div>
@else
    <ul class="entry-list">
        @foreach ($entries as $entry)
            <li class="entry-row">
                <a class="entry-link" href="{{ route('diary.show', $entry->entry_date->format('Y-m-d')) }}">
                    <div class="entry-date">
                        <span class="day">{{ $entry->entry_date->format('d') }}</span>
                        <span class="month">{{ $entry->entry_date->format('Y-m') }}</span>
                        <span class="weekday">周{{ $weekdays[$entry->entry_date->dayOfWeek] }}</span>
                    </div>
                    <div class="entry-main">
                        <h2>{{ $entry->title ?: '无标题' }}</h2>
                        <p>{{ \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($entry->body ?? ''))), 120) }}</p>
                        <div class="tag-list">
                            @foreach ($entry->tags as $tag)
                                <a class="tag" href="{{ route('diary.index', ['tag' => $tag->name]) }}">{{ $tag->name }}</a>
                            @endforeach
                            @if ($entry->attachments->isNotEmpty())
                                <span class="tag">{{ $entry->attachments->count() }} 张图片</span>
                            @endif
                        </div>
                    </div>
                </a>
                <div class="entry-meta">
                    @if ($entry->status === 'draft')
                        <span class="tag">草稿</span>
                    @endif
                    <a href="{{ route('diary.show', $entry->entry_date->format('Y-m-d')) }}">查看</a>
                    <a href="{{ route('diary.create', ['date' => $entry->entry_date->format('Y-m-d')]) }}">编辑</a>
                </div>
            </li>
        @endforeach
    </ul>

    <div class="pagination">{{ $entries->links() }}</div>
@endif
@endsection
