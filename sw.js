/**
 * OptiLifeSync — PWA Service Worker (v4)
 *
 * Hız stratejisi:
 *  - Sayfalar (HTML): her zaman ağdan, "navigation preload" ile (SW açılışını beklemeden).
 *    Sayfa HTML'i ASLA önbelleğe alınmaz (kişisel veri + her zaman güncel).
 *  - /api/ istekleri: SW hiç karışmaz (doğrudan ağ).
 *  - Uygulama dosyaları (/assets/, sürümlü ?v=...): önbellekten anında, yoksa ağdan.
 *  - CDN kütüphaneleri (Bootstrap, ikonlar, SweetAlert, Chart.js, yazı tipi): önbellekten anında.
 *  → Modül geçişlerinde yalnızca sayfanın HTML'i indirilir.
 */

const VERSION = 'v4-20260930';
const STATIC_CACHE = 'opti-static-' + VERSION;
const CDN_CACHE = 'opti-cdn-v1';

const PRECACHE_LOCAL = [
    'manifest.json',
    'favicon.ico',
    'assets/icons/icon-192.png',
    'assets/icons/icon-512.png',
    'assets/css/sidebar.css?v=20260930',
    'assets/css/theme.css?v=20260930',
    'assets/js/native-bridge.js?v=20260930',
    'assets/js/alarm-engine.js?v=20260930'
];

const PRECACHE_CDN = [
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js',
    'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css',
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js'
];

const CDN_HOSTS = ['cdn.jsdelivr.net', 'fonts.gstatic.com', 'fonts.googleapis.com'];

const OFFLINE_HTML = `<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Çevrimdışı · OptiLifeSync</title>
<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#080d19;color:#e2e8f0;text-align:center;padding:24px}
.b{max-width:340px}h1{font-size:20px;margin:16px 0 8px}p{color:#94a3b8;font-size:14px;line-height:1.5}
button{margin-top:18px;border:0;border-radius:12px;padding:12px 22px;font-weight:600;font-size:15px;
background:linear-gradient(135deg,#10b981,#0d9488);color:#fff}</style></head>
<body><div class="b"><div style="font-size:44px">📡</div><h1>Bağlantı yok</h1>
<p>İnternet bağlantısı kurulamadı. Bağlantı gelince sayfa kendiliğinden yenilenir.</p>
<button onclick="location.reload()">Tekrar dene</button></div>
<script>addEventListener('online',()=>location.reload())</script></body></html>`;

function offlineResponse() {
    return new Response(OFFLINE_HTML, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

async function precache(cacheName, urls) {
    const cache = await caches.open(cacheName);
    await Promise.all(urls.map(async (u) => {
        try {
            if (await cache.match(u)) return;
            const res = await fetch(u, { cache: 'no-cache' });
            if (res && (res.ok || res.type === 'opaque')) await cache.put(u, res);
        } catch (_) { /* bir dosya inmese de kurulum sürer */ }
    }));
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        Promise.all([precache(STATIC_CACHE, PRECACHE_LOCAL), precache(CDN_CACHE, PRECACHE_CDN)])
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys.filter((k) => k !== STATIC_CACHE && k !== CDN_CACHE).map((k) => caches.delete(k)));
        if (self.registration.navigationPreload) {
            try { await self.registration.navigationPreload.enable(); } catch (_) {}
        }
        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

async function cacheFirst(request, cacheName) {
    const cache = await caches.open(cacheName);
    const hit = await cache.match(request);
    if (hit) return hit;
    const res = await fetch(request);
    if (res && (res.ok || res.type === 'opaque')) cache.put(request, res.clone()).catch(() => {});
    return res;
}

async function staleWhileRevalidate(request, cacheName) {
    const cache = await caches.open(cacheName);
    const hit = await cache.match(request);
    const net = fetch(request).then((res) => {
        if (res && (res.ok || res.type === 'opaque')) cache.put(request, res.clone()).catch(() => {});
        return res;
    }).catch(() => hit);
    return hit || net;
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // 1) Sayfa geçişleri: ağdan (navigation preload), bağlantı yoksa çevrimdışı ekranı.
    if (request.mode === 'navigate') {
        event.respondWith((async () => {
            try {
                const preloaded = await event.preloadResponse;
                if (preloaded) return preloaded;
                return await fetch(request);
            } catch (_) {
                return offlineResponse();
            }
        })());
        return;
    }

    // 2) CDN kütüphaneleri ve yazı tipleri
    if (CDN_HOSTS.includes(url.hostname)) {
        if (url.hostname === 'fonts.googleapis.com') {
            event.respondWith(staleWhileRevalidate(request, CDN_CACHE));
        } else {
            event.respondWith(cacheFirst(request, CDN_CACHE));
        }
        return;
    }

    if (url.origin !== self.location.origin) return;

    // 3) API ve PHP: SW karışmaz
    if (url.pathname.startsWith('/api/') || url.pathname.endsWith('.php')) return;

    // 4) Uygulama dosyaları (css/js/ikon/görsel): önbellekten anında
    if (url.pathname.startsWith('/assets/') || url.pathname === '/favicon.ico' || url.pathname === '/manifest.json') {
        const versioned = url.searchParams.has('v') || url.pathname.startsWith('/assets/icons/') || url.pathname.startsWith('/assets/img/');
        event.respondWith(versioned ? cacheFirst(request, STATIC_CACHE) : staleWhileRevalidate(request, STATIC_CACHE));
    }
});

// Push bildirimleri
self.addEventListener('push', (event) => {
    let data = {};
    if (event.data) {
        try { data = event.data.json(); } catch (e) { data = { title: 'OptiLifeSync', body: event.data.text() }; }
    }
    const title = data.title || '🔔 OptiLifeSync';
    event.waitUntil(self.registration.showNotification(title, {
        body: data.body || 'İlaç veya antrenman vaktiniz geldi!',
        icon: 'assets/icons/icon-192.png',
        badge: 'assets/icons/icon-192.png',
        vibrate: [200, 100, 200],
        tag: data.tag || 'optilifesync-notification',
        requireInteraction: true,
        data: { url: data.url || 'reminders.php' }
    }));
});

// Bildirime tıklanınca uygulamayı aç / öne getir
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || 'reminders.php', self.registration.scope).href;
    event.waitUntil((async () => {
        const wins = await clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const c of wins) {
            if (c.url.startsWith(self.registration.scope)) {
                try { await c.focus(); if (c.url !== target && 'navigate' in c) await c.navigate(target); } catch (_) {}
                return;
            }
        }
        if (clients.openWindow) return clients.openWindow(target);
    })());
});
