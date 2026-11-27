/* Theme switch: auto -> light -> dark -> auto. Preference stays on this device. */
(function (window) {
    'use strict';

    var KEY = 'private-diary:theme';
    var query = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function mode() {
        try { return window.localStorage.getItem(KEY) || 'auto'; } catch (e) { return 'auto'; }
    }

    function resolved() {
        var m = mode();
        if (m === 'dark' || m === 'light') return m;
        return query && query.matches ? 'dark' : 'light';
    }

    function apply() {
        var m = mode();
        var dark = resolved() === 'dark';
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';

        var button = document.getElementById('theme-toggle');
        if (button) {
            button.textContent = m === 'auto' ? '自动主题' : (dark ? '深色主题' : '浅色主题');
            button.setAttribute('aria-label', '切换主题，当前：' + (m === 'auto' ? '跟随系统' : (dark ? '深色' : '浅色')));
            button.dataset.mode = m;
        }
    }

    apply();

    if (query && query.addEventListener) {
        query.addEventListener('change', function () { if (mode() === 'auto') apply(); });
    }

    var button = document.getElementById('theme-toggle');
    if (button) {
        button.addEventListener('click', function () {
            var m = mode();
            var next = m === 'auto' ? 'light' : (m === 'light' ? 'dark' : 'auto');
            try { window.localStorage.setItem(KEY, next); } catch (e) { /* 隐私模式 */ }
            apply();
        });
    }
})(window);
