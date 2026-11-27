@extends('layouts.app')

@section('title', '日记 · '.config('app.name'))

@section('content')
@php($weekdays = ['日', '一', '二', '三', '四', '五', '六'])
@php($base = array_filter(['q' => $keyword, 'mood' => $mood, 'weather' => $weather, 'from' => $from, 'to' => $to]))
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
    <input type="search" name="q" value="{{ $keyword }}" placeholder="搜索标题或正文（至少 3 字，短词也支持）" aria-label="搜索关键词">
    <select name="mood" aria-label="按心情筛选">
        <option value="">全部心情</option>
        @foreach ($moods as $key => $label)
            <option value="{{ $key }}" @selected($mood === $key)>{{ $label }}</option>
        @endforeach
    </select>
    <select name="weather" aria-label="按天气筛选">
        <option value="">全部天气</option>
        @foreach ($weathers as $key => $label)
            <option value="{{ $key }}" @selected($weather === $key)>{{ $label }}</option>
        @endforeach
    </select>
    <input type="date" name="from" value="{{ $from }}" aria-label="开始日期">
    <span class="date-sep">至</span>
    <input type="date" name="to" value="{{ $to }}" aria-label="结束日期">
    <button type="submit">筛选</button>
    @if ($base !== [] || $activeTag !== '')
        <a href="{{ route('diary.index') }}">清除</a>
    @endif
</form>

<div class="filter-bar">
    <span class="muted">标签</span>
    <a class="tag-filter {{ $activeTag === '' ? 'active' : '' }}" href="{{ route('diary.index', $base) }}">全部</a>
    @forelse ($tags as $tag)
        <a class="tag-filter {{ $activeTag === $tag->name ? 'active' : '' }}"
           href="{{ route('diary.index', $base + ['tag' => $tag->name]) }}">
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
                        <h2>{!! $highlight->text($entry->title ?: '无标题', $keyword) !!}</h2>
                        <p>{!! $highlight->text(\Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($entry->body ?? ''))), 120), $keyword) !!}</p>
                        <div class="entry-flags">
                            @if ($entry->mood)
                                <a class="mood-chip mood-{{ $entry->mood }}" href="{{ route('diary.index', ['mood' => $entry->mood]) }}">{!! $icons->mood($entry->mood) !!}<span>{{ $moods[$entry->mood] ?? $entry->mood }}</span></a>
                            @endif
                            @if ($entry->weather)
                                <a class="weather-chip" href="{{ route('diary.index', ['weather' => $entry->weather]) }}">{!! $icons->weather($entry->weather) !!}<span>{{ $weathers[$entry->weather] ?? $entry->weather }}</span></a>
                            @endif
                        </div>
                        <div class="tag-list">
                            @foreach ($entry->tags as $tag)
                                <a class="tag" href="{{ route('diary.index', $base + ['tag' => $tag->name]) }}">{{ $tag->name }}</a>
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
