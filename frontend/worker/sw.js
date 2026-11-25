const CACHE_NAME = 'hms-worker-v92';
const FETCH_TIMEOUT_MS = 12000;

// fetch() has no built-in timeout — a stalled connection on a bad network
// would hang respondWith() forever (black page). Race it so the page
// always gets a response: network result, cache, or a 503.
function netWithTimeout(request, ms) {
    return new Promise((resolve, reject) => {
        const t = setTimeout(() => reject(new Error('sw-timeout')), ms || FETCH_TIMEOUT_MS);
        fetch(request).then(
            r => { clearTimeout(t); resolve(r); },
            e => { clearTimeout(t); reject(e); }
        );
    });
}

// Real Web-Push from the backend (arrives even when the app is closed).
// Payload: { title, body, tab }. If the app is open & visible in the
// foreground, skip the banner — the in-app poll shows a toast instead.
self.addEventListener('push', event => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { /* raw text */ }
    const title = data.title || 'HMS';
    const tab = data.tab || 'feed';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clients => {
            const visible = clients.some(c => c.visibilityState === 'visible');
            if (visible) return;
            return self.registration.showNotification(title, {
                body: data.body || '',
                icon: './assets/icons/icon-192.png',
                badge: './assets/icons/icon-192.png',
                tag: 'hms-push-' + tab,
                renotify: true,
                data: { tab: tab }
            });
        })
    );
});

// Tap on a system notification: focus the running app (or open it) and jump
// to the tab carried in notification.data.tab (plan/chat/profil/frei/feed).
self.addEventListener('notificationclick', event => {
    event.notification.close();
    const tab = (event.notification.data && event.notification.data.tab) || 'feed';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clients => {
            for (const client of clients) {
                if ('focus' in client) {
                    client.focus();
                    client.postMessage({ action: 'switch-tab', tab: tab, title: event.notification.title });
                    return;
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow('./index.php#' + tab);
            }
        })
    );
});
const STATIC_ASSETS = [
    './assets/css/md3-tokens.css',
    './assets/css/app.css',
    './assets/css/ms-icons.css',
    './assets/css/qm-wachbuch.css?v=20261118qmfix2',
    './assets/js/qm-wachbuch.js?v=20261118qmfix2',
    './assets/js/qm-live.js?v=20261109live1',
    './assets/fonts/material-symbols-rounded.woff2',
    './assets/js/storage.js',
    './assets/js/app.js?v=20261020fx1',
    './assets/js/call.js?v=20260929call6',
    './assets/js/capacitor-bridge.js'
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Never cache API calls or PHP pages (they need session auth)
    if (url.pathname.includes('/api/') || url.pathname.endsWith('.php')) {
        return event.respondWith(
            netWithTimeout(event.request).catch(() => caches.match(event.request))
        );
    }

    // Cache-first for static assets (CSS, JS, fonts, icons)
    event.respondWith(
        caches.match(event.request).then(cached => {
            if (cached) return cached;
            return netWithTimeout(event.request).then(response => {
                if (response.status === 200) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
                }
                return response;
            });
        }).catch(() => caches.match(event.request).then(c => c || new Response('Offline', { status: 503 })))
    );
});
