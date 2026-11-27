@extends('layouts.app')

@section('title', '统计 · '.config('app.name'))

@section('content')
<div class="page-head">
    <div>
        <h1>统计</h1>
        <p class="muted">看看这一年留下了多少文字。</p>
    </div>
    <form method="GET" action="{{ route('diary.stats') }}" class="jump-form">
        <label for="stats-year">年份</label>
        <select id="stats-year" name="year" onchange="this.form.submit()">
            @foreach (array_unique(array_merge([now()->year], $stats['years'])) as $year)
                <option value="{{ $year }}" {{ $year === $stats['year'] ? 'selected' : '' }}>{{ $year }}</option>
            @endforeach
        </select>
    </form>
</div>

<div class="stat-grid">
    <section class="export-card stat-card">
        <div class="stat-value">{{ $stats['total'] }}</div>
        <div class="stat-label">篇日记</div>
    </section>
    <section class="export-card stat-card">
        <div class="stat-value">{{ number_format($stats['words']) }}</div>
        <div class="stat-label">个字</div>
    </section>
    <section class="export-card stat-card">
        <div class="stat-value">{{ $stats['days'] }}</div>
        <div class="stat-label">个写作日</div>
    </section>
    <section class="export-card stat-card">
        <div class="stat-value">{{ $stats['longestStreak'] }}</div>
        <div class="stat-label">天最长连续</div>
    </section>
</div>

<section class="export-card stats-section">
    <h2>{{ $stats['year'] }} 年热力图</h2>
    <p class="muted">当前连续 {{ $stats['currentStreak'] }} 天。颜色越深，当天写得越多。</p>
    <div class="heatmap">
        @foreach ($stats['heatmap'] as $week)
            <div class="heatmap-week">
                @foreach ($week as $day)
                    <span class="heatmap-day level-{{ $day['level'] }} {{ $day['outside'] ? 'outside' : '' }} {{ $day['future'] ? 'future' : '' }}"
                          title="{{ $day['date'] }} · {{ $day['count'] }} 篇"></span>
                @endforeach
            </div>
        @endforeach
    </div>
    <div class="heatmap-legend">
        <span>少</span>
        @for ($level = 0; $level <= 4; $level++)
            <span class="heatmap-day level-{{ $level }}"></span>
        @endfor
        <span>多</span>
    </div>
</section>

<div class="export-grid stats-lower">
    <section class="export-card">
        <h2>每月篇数</h2>
        <div class="month-bars">
            @foreach ($stats['monthly'] as $month => $count)
                <div class="month-bar">
                    <span class="month-bar-value">{{ $count }}</span>
                    <div class="month-bar-track">
                        <div class="month-bar-fill" style="height: {{ $stats['total'] > 0 ? max(4, (int) round($count / max($stats['monthly']) * 100)) : 0 }}%"></div>
                    </div>
                    <span class="month-bar-label">{{ $month }}</span>
                </div>
            @endforeach
        </div>
    </section>

    <section class="export-card">
        <h2>常用标签</h2>
        @forelse ($stats['topTags'] as $name => $count)
            <div class="tag-rank">
                <span>{{ $name }}</span>
                <span class="muted">{{ $count }} 篇</span>
            </div>
        @empty
            <p class="muted">这一年还没有使用标签。</p>
        @endforelse
    </section>
</div>
@endsection
