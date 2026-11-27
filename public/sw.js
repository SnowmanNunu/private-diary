/* private-diary service worker.
 * Privacy rule: only an explicit allow-list of public static assets is cached.
 * Diary pages, attachments, search results and every POST go straight to the network.
 */
'use strict';

const CACHE = 'private-diary-shell-v4';
const SHELL = [
    '/offline',
    '/css/app.css',
    '/js/offline-drafts.js',
    '/js/theme.js',
    '/manifest.webmanifest',
    '/icons/icon.svg',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll(SHELL))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

async function staleWhileRevalidate(path) {
    const cache = await caches.open(CACHE);
    const cached = await cache.match(path);
    const network = fetch(path)
        .then((response) => {
            if (response.ok) cache.put(path, response.clone());
            return response;
        })
        .catch(() => cached);
    return cached || network;
}

async function offlineNavigation() {
    const cache = await caches.open(CACHE);
    return (await cache.match('/offline')) || Response.error();
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (SHELL.includes(url.pathname)) {
        // Normalise away cache-busting query strings (?v=4).
        event.respondWith(staleWhileRevalidate(url.pathname));
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(offlineNavigation)
        );
    }
});

self.addEventListener('sync', (event) => {
    if (event.tag !== 'diary-sync') return;
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((clients) => {
                clients.forEach((client) => client.postMessage({ type: 'SYNC_DRAFTS' }));
            })
    );
});
