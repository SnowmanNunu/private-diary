@extends('layouts.app')

@section('title', '日历 · '.config('app.name'))

@section('content')
<div class="page-head">
    <div>
        <h1>日历</h1>
        <p class="muted">{{ $month->format('Y 年 m 月') }} · 本月 {{ $monthCount }} 篇</p>
    </div>
    <a class="primary" href="{{ route('diary.create') }}">写今天</a>
</div>

<div class="calendar-nav">
    <a href="{{ route('diary.calendar', ['month' => $prevMonth]) }}">← 上个月</a>
    <a href="{{ route('diary.calendar') }}">回到本月</a>
    <a href="{{ route('diary.calendar', ['month' => $nextMonth]) }}">下个月 →</a>
</div>

<div class="calendar">
    <div class="calendar-weekdays">
        @foreach (['一', '二', '三', '四', '五', '六', '日'] as $weekday)
            <span>{{ $weekday }}</span>
        @endforeach
    </div>

    @foreach ($weeks as $week)
        <div class="calendar-week">
            @foreach ($week as $day)
                <div class="calendar-day {{ $day['inMonth'] ? '' : 'outside' }} {{ $day['isToday'] ? 'today' : '' }} {{ $day['entry'] ? 'has-entry' : '' }}">
                    <span class="calendar-number">{{ $day['day'] }}</span>
                    @if ($day['inMonth'] && $day['entry'])
                        <a class="calendar-entry" href="{{ route('diary.show', $day['date']) }}">
                            {{ $day['entry']->title ?: '无标题' }}
                        </a>
                    @elseif ($day['inMonth'])
                        <a class="calendar-write" href="{{ route('diary.create', ['date' => $day['date']]) }}">写</a>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
</div>
@endsection
