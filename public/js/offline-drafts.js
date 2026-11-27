/* private-diary: browser-side draft queue.
 * Drafts are plain text only (no images) and stay in this browser until synced.
 */
(function (window) {
    'use strict';

    var DRAFTS_KEY = 'private-diary:drafts:v1';
    var META_KEY = 'private-diary:sync-meta:v1';
    var FIELDS = ['title', 'tags', 'mood', 'weather', 'body'];
    var registration = null;

    function read(key, fallback) {
        try {
            var raw = window.localStorage.getItem(key);
            return raw ? JSON.parse(raw) : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function write(key, value) {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
            return true;
        } catch (e) {
            return false;
        }
    }

    function all() {
        var drafts = read(DRAFTS_KEY, {});
        return drafts && typeof drafts === 'object' && !Array.isArray(drafts) ? drafts : {};
    }

    function isDate(value) {
        return /^\d{4}-\d{2}-\d{2}$/.test(value);
    }

    function get(date) {
        if (!isDate(date)) return null;
        var draft = all()[date];
        if (!draft) return null;
        return FIELDS.every(function (field) { return typeof draft[field] === 'string'; }) ? draft : null;
    }

    function put(date, fields) {
        if (!isDate(date)) return null;
        var drafts = all();
        var draft = { date: date, updatedAt: new Date().toISOString(), pending: true };
        FIELDS.forEach(function (field) {
            draft[field] = fields && typeof fields[field] === 'string' ? fields[field] : '';
        });
        drafts[date] = draft;
        write(DRAFTS_KEY, drafts);
        return draft;
    }

    function markSynced(date) {
        var drafts = all();
        if (drafts[date]) {
            drafts[date].pending = false;
            drafts[date].syncedAt = new Date().toISOString();
            write(DRAFTS_KEY, drafts);
        }
    }

    function remove(date) {
        var drafts = all();
        delete drafts[date];
        write(DRAFTS_KEY, drafts);
    }

    function clear() {
        window.localStorage.removeItem(DRAFTS_KEY);
    }

    function pending() {
        return Object.keys(all())
            .map(get)
            .filter(function (draft) { return draft && draft.pending; })
            .sort(function (a, b) { return a.date.localeCompare(b.date); });
    }

    function setMeta(meta) {
        write(META_KEY, { csrf: meta && meta.csrf ? meta.csrf : '', savedAt: Date.now() });
    }

    function getMeta() {
        var meta = read(META_KEY, { csrf: '', savedAt: 0 });
        return meta && typeof meta === 'object' ? meta : { csrf: '', savedAt: 0 };
    }

    async function syncOne(draft) {
        var meta = getMeta();
        if (!meta.csrf) throw new Error('NO_TOKEN');
        var payload = {};
        FIELDS.forEach(function (field) { payload[field] = draft[field]; });
        payload.autosave = true;

        var response = await window.fetch('/diary/' + encodeURIComponent(draft.date), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': meta.csrf,
            },
            body: JSON.stringify(payload),
        });

        if (response.status === 419) throw new Error('SESSION_EXPIRED');
        if (!response.ok) throw new Error('HTTP_' + response.status);
        markSynced(draft.date);
        return response.json();
    }

    async function syncAll(onProgress) {
        var list = pending();
        var result = { ok: 0, failed: 0, expired: false, total: list.length };

        for (var i = 0; i < list.length; i++) {
            try {
                await syncOne(list[i]);
                result.ok++;
            } catch (e) {
                result.failed++;
                if (e && e.message === 'SESSION_EXPIRED') {
                    result.expired = true;
                    break;
                }
            }
            if (onProgress) onProgress(result);
        }
        return result;
    }

    function register() {
        if (registration) return registration;
        if (!('serviceWorker' in window.navigator)) return Promise.resolve(null);
        registration = window.navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () { return null; });
        return registration;
    }

    function requestBackgroundSync() {
        if (!('serviceWorker' in window.navigator)) return;
        register().then(function (worker) {
            if (worker && worker.sync) worker.sync.register('diary-sync').catch(function () {});
        });
    }

    function onSync(handler) {
        window.addEventListener('diary:sync-request', handler);
    }

    function initStatus() {
        var el = document.getElementById('pwa-status');
        if (!el) return;
        var update = function () {
            var count = pending().length;
            var offline = !window.navigator.onLine;
            el.hidden = !count && !offline;
            el.classList.toggle('has-drafts', count > 0);
            el.textContent = offline ? '离线模式' : ('本机草稿 ' + count);
        };
        update();
        window.addEventListener('online', update);
        window.addEventListener('offline', update);
        window.addEventListener('storage', update);
    }

    if ('serviceWorker' in window.navigator && window.navigator.serviceWorker.addEventListener) {
        window.navigator.serviceWorker.addEventListener('message', function (event) {
            if (event && event.data && event.data.type === 'SYNC_DRAFTS') {
                window.dispatchEvent(new CustomEvent('diary:sync-request'));
            }
        });
    }

    window.OfflineDrafts = {
        all: all,
        get: get,
        put: put,
        markSynced: markSynced,
        remove: remove,
        clear: clear,
        pending: pending,
        setMeta: setMeta,
        getMeta: getMeta,
        syncOne: syncOne,
        syncAll: syncAll,
        register: register,
        requestBackgroundSync: requestBackgroundSync,
        onSync: onSync,
        initStatus: initStatus,
    };
})(window);
