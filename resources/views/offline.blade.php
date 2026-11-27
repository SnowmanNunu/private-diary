<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#3c6b5e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="私密日记">
    <title>离线写作 · {{ config('app.name') }}</title>
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
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=6">
</head>
<body class="offline-body">
<div class="shell">
    <header class="topbar">
        <a class="brand" href="{{ route('pwa.offline') }}">
            <span class="brand-mark">日</span>
            {{ config('app.name') }}
        </a>
        <nav class="topnav">
            <span class="pwa-status" id="network-state">离线写作</span>
            <button type="button" class="link-button theme-toggle" id="theme-toggle" aria-label="切换主题">自动主题</button>
            <a href="/diary">返回日记</a>
        </nav>
    </header>

    <main>
        <div class="page-head">
            <div>
                <h1>离线写作</h1>
                <p class="muted">内容先保存在这台设备的浏览器里；联网并登录后点「同步」写入服务器。</p>
            </div>
        </div>

        <div class="offline-layout">
            <aside class="draft-list" aria-label="本机草稿">
                <h2>本机草稿 <span class="muted" id="draft-count"></span></h2>
                <ul id="draft-items"></ul>
                <button type="button" id="clear-button" class="link-button danger">清空本机草稿</button>
            </aside>

            <section class="offline-editor">
                <div class="field-row">
                    <label for="draft-date">日期</label>
                    <input id="draft-date" type="date" min="1900-01-01">
                </div>
                <label class="sr-only" for="draft-title">标题</label>
                <input id="draft-title" type="text" class="title-input" placeholder="标题（可留空）" maxlength="200">
                <div class="field-row">
                    <label for="draft-tags">标签</label>
                    <input id="draft-tags" type="text" class="tags-input" placeholder="用逗号分隔">
                </div>
                <div class="field-row">
                    <label for="draft-mood">心情</label>
                    <select id="draft-mood">
                        <option value="">未记录</option>
                        @foreach ($moods as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field-row">
                    <label for="draft-weather">天气</label>
                    <select id="draft-weather">
                        <option value="">未记录</option>
                        @foreach ($weathers as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="sr-only" for="draft-body">正文</label>
                <textarea id="draft-body" class="body-input" placeholder="用 Markdown 写点什么……"></textarea>
            </section>
        </div>

        <div class="editor-actions">
            <button type="button" id="local-button" class="primary">保存到本机</button>
            <button type="button" id="sync-button">同步到服务器</button>
            <a id="relogin-link" class="primary" href="/diary" hidden>去登录</a>
            <span class="muted" id="offline-state"></span>
        </div>
    </main>
</div>

<script src="{{ asset('js/offline-drafts.js') }}"></script>
<script src="{{ asset('js/theme.js') }}"></script>
<script>
(function () {
    var drafts = window.OfflineDrafts;
    var byId = function (id) { return document.getElementById(id); };
    var date = byId('draft-date');
    var title = byId('draft-title');
    var tags = byId('draft-tags');
    var mood = byId('draft-mood');
    var weather = byId('draft-weather');
    var body = byId('draft-body');
    var state = byId('offline-state');
    var network = byId('network-state');
    var items = byId('draft-items');
    var count = byId('draft-count');
    var timer = null;
    var loadedDate = null;

    function today() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function fields() {
        var existing = drafts.get(date.value);
        return {
            title: title.value,
            tags: tags.value,
            mood: mood.value,
            weather: weather.value,
            body: body.value,
            revision: existing && typeof existing.revision === 'number' ? existing.revision : 0,
        };
    }

    function say(message) { state.textContent = message; }

    function reasonText(reason) {
        var code = reason.code || 'UNKNOWN';
        var text;
        switch (code) {
            case 'AUTH_REQUIRED': text = '登录状态已失效或尚未完成二重验证'; break;
            case 'SESSION_EXPIRED': text = '登录会话已过期'; break;
            case 'NO_TOKEN': text = '本机缺少登录凭证，请先打开一次在线日记'; break;
            case 'OFFLINE': text = '当前处于离线状态'; break;
            case 'NETWORK': text = '网络请求失败'; break;
            case 'BAD_RESPONSE': text = '服务器返回的不是有效数据'; break;
            case 'HTTP_422': text = '内容未通过校验'; break;
            case 'HTTP_500': text = '服务器内部错误'; break;
            default: text = code.indexOf('HTTP_') === 0 ? '服务器返回 ' + code.slice(5) : code;
        }
        return reason.detail ? text + '：' + reason.detail : text;
    }

    function reasonsText(result) {
        return result.reasons.map(reasonText).join('；');
    }

    function renderList() {
        var draftsById = drafts.all();
        var dates = Object.keys(draftsById).sort().reverse();
        count.textContent = '(' + dates.length + ')';
        items.textContent = '';

        if (!dates.length) {
            var empty = document.createElement('li');
            empty.className = 'muted';
            empty.textContent = '暂无本机草稿';
            items.appendChild(empty);
            return;
        }

        dates.forEach(function (value) {
            var draft = draftsById[value];
            var li = document.createElement('li');
            li.className = 'draft-item' + (draft.conflict ? ' conflict' : (draft.pending ? ' pending' : ''));
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'link-button';
            button.textContent = value + (draft.conflict ? ' · 版本冲突' : (draft.pending ? ' · 待同步' : ' · 已同步'));
            button.addEventListener('click', function () { load(value); });
            li.appendChild(button);

            if (draft.conflict) {
                var actions = document.createElement('div');
                actions.className = 'draft-conflict-actions';
                var overwrite = document.createElement('button');
                overwrite.type = 'button';
                overwrite.className = 'link-button';
                overwrite.textContent = '用本机内容覆盖';
                overwrite.addEventListener('click', async function () {
                    try {
                        await drafts.overwrite(value);
                        renderList();
                        load(value);
                        say(value + ' 已用本机内容覆盖服务器版本。');
                    } catch (e) {
                        var reason = e && e.code ? reasonText({ code: e.code, detail: e.detail }) : '网络异常';
                        var needsLogin = e && (e.code === 'AUTH_REQUIRED' || e.code === 'SESSION_EXPIRED');
                        byId('relogin-link').hidden = !needsLogin;
                        say('覆盖失败（' + reason + '），' + (needsLogin ? '请点「去登录」后重试。' : '请稍后重试，或到在线编辑器处理。'));
                    }
                });
                var discard = document.createElement('button');
                discard.type = 'button';
                discard.className = 'link-button danger';
                discard.textContent = '删除本机副本';
                discard.addEventListener('click', function () {
                    if (!confirm('删除这份本机副本？服务器上的内容会保留。')) return;
                    drafts.remove(value);
                    renderList();
                    load(value);
                    say('已删除本机副本。');
                });
                actions.append(overwrite, discard);
                li.appendChild(actions);
            }

            items.appendChild(li);
        });
    }

    function load(value) {
        var draft = drafts.get(value);
        loadedDate = value;
        date.value = value;
        title.value = draft ? draft.title : '';
        tags.value = draft ? draft.tags : '';
        mood.value = draft ? draft.mood : '';
        weather.value = draft ? draft.weather : '';
        body.value = draft ? draft.body : '';
        say(draft ? ('已载入 ' + value + ' 的本机草稿。') : '');
    }

    function saveLocal() {
        var draft = drafts.put(date.value, fields());
        renderList();
        if (draft) say('已保存到本机 ' + new Date(draft.updatedAt).toLocaleTimeString('zh-CN'));
    }

    function scheduleLocalSave() {
        clearTimeout(timer);
        timer = setTimeout(saveLocal, 600);
    }

    async function sync() {
        if (!drafts.getMeta().csrf) {
            say('请先联网登录一次在线日记，再回来同步。');
            return;
        }
        say('正在同步…');
        byId('relogin-link').hidden = true;
        var result = await drafts.syncAll();
        renderList();
        if (result.expired || result.authRequired) {
            byId('relogin-link').hidden = false;
            say('登录状态已失效（已退出、会话过期或未完成二重验证）。点「去登录」重新登录，再回来点同步；草稿仍在本机。');
        } else if (result.conflicts) {
            say(result.conflicts + ' 篇发生版本冲突，内容已保留，请在列表中选择覆盖或删除。'
                + (result.failed ? ' 另有 ' + result.failed + ' 篇未同步：' + reasonsText(result) + '。' : ''));
        } else if (result.failed) {
            say('同步失败 ' + result.failed + ' 篇（' + reasonsText(result) + '），草稿仍在本机，可稍后重试。');
        } else if (result.total) say('已同步 ' + result.ok + ' 篇。');
        else if (drafts.conflicts().length) say('有 ' + drafts.conflicts().length + ' 篇版本冲突待处理。');
        else say('没有待同步的草稿。');
    }

    function updateNetwork() {
        var offline = !navigator.onLine;
        network.textContent = offline ? '当前离线' : '当前在线';
        network.classList.toggle('has-drafts', offline);
    }

    [title, tags, body, mood, weather].forEach(function (element) {
        element.addEventListener('input', scheduleLocalSave);
        element.addEventListener('change', scheduleLocalSave);
    });

    date.addEventListener('change', function () {
        if (loadedDate && loadedDate !== date.value && (title.value || tags.value || body.value)) {
            drafts.put(loadedDate, fields());
            renderList();
        }
        load(date.value);
    });
    byId('local-button').addEventListener('click', saveLocal);
    byId('sync-button').addEventListener('click', sync);
    byId('clear-button').addEventListener('click', function () {
        if (!confirm('确定清空这台设备上的全部本机草稿？此操作不可撤销。')) return;
        drafts.clear();
        renderList();
        load(today());
        say('本机草稿已清空。');
    });

    window.addEventListener('online', function () { updateNetwork(); sync(); });
    window.addEventListener('offline', updateNetwork);
    drafts.onSync(sync);

    updateNetwork();
    renderList();
    var pendingDrafts = drafts.pending();
    load(pendingDrafts.length ? pendingDrafts[0].date : today());
    drafts.register();
})();
</script>
</body>
</html>
