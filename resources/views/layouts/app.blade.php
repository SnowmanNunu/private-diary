<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=2">
</head>
<body>
<div class="shell">
    @if (auth()->check())
        <header class="topbar">
            <a class="brand" href="{{ route('diary.index') }}">
                <span class="brand-mark">日</span>
                {{ config('app.name') }}
            </a>
            <nav class="topnav">
                <a class="{{ request()->routeIs('diary.index', 'diary.show') ? 'active' : '' }}" href="{{ route('diary.index') }}">日记</a>
                <a class="{{ request()->routeIs('diary.calendar') ? 'active' : '' }}" href="{{ route('diary.calendar') }}">日历</a>
                <a class="{{ request()->routeIs('diary.stats') ? 'active' : '' }}" href="{{ route('diary.stats') }}">统计</a>
                <a class="{{ request()->routeIs('diary.export', 'diary.import*') ? 'active' : '' }}" href="{{ route('diary.export') }}">导出</a>
                <a class="{{ request()->routeIs('security.*', 'two-factor.*') ? 'active' : '' }}" href="{{ route('security.index') }}">安全</a>
                <a class="{{ request()->routeIs('diary.create', 'diary.store') ? 'active' : '' }}" href="{{ route('diary.create') }}">写今天</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="link-button">退出</button>
                </form>
            </nav>
        </header>
    @endif

    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif

    <main>
        @yield('content')
    </main>

    @if (auth()->check())
        <footer class="shell-footer">私密日记 · 数据只保存在你自己的空间</footer>
    @endif
</div>
@stack('scripts')
</body>
</html>
