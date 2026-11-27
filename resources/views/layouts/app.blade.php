<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#3c6b5e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="私密日记">
    <title>@yield('title', config('app.name'))</title>
    <script>
        (function () {
            try {
                var m = localStorage.getItem('private-diary:theme') || 'auto';
                var dark = m === 'dark' || (m === 'auto' && matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
            } catch (e) { /* 隐私模式 */ }
        })();
    </script>
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-192.png') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=5">
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
                <a class="{{ request()->routeIs('settings.*') ? 'active' : '' }}" href="{{ route('settings.index') }}">设置</a>
                <a class="{{ request()->routeIs('security.*', 'two-factor.*') ? 'active' : '' }}" href="{{ route('security.index') }}">安全</a>
                <a class="{{ request()->routeIs('diary.create', 'diary.store') ? 'active' : '' }}" href="{{ route('diary.create') }}">写今天</a>
                <a class="{{ request()->routeIs('pwa.offline') ? 'active' : '' }}" href="{{ route('pwa.offline') }}">离线写作</a>
                <span class="pwa-status" id="pwa-status" hidden></span>
                <button type="button" class="link-button theme-toggle" id="theme-toggle" aria-label="切换主题">自动主题</button>
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
<script src="{{ asset('js/offline-drafts.js') }}"></script>
<script src="{{ asset('js/theme.js') }}"></script>
@if (auth()->check())
<script>
(function () {
    var drafts = window.OfflineDrafts;
    drafts.setMeta({ csrf: @json(csrf_token()) });
    drafts.register();
    drafts.initStatus();

    var syncing = false;
    async function syncPending() {
        if (syncing) return;
        syncing = true;
        try {
            var result = await drafts.syncAll();
            var state = document.getElementById('save-state');
            if (result.ok > 0 && state) state.textContent = '已同步 ' + result.ok + ' 篇本机草稿。';
        } finally {
            syncing = false;
        }
    }

    window.addEventListener('online', syncPending);
    drafts.onSync(syncPending);
    syncPending();
})();
</script>
@endif
@stack('scripts')
</body>
</html>
