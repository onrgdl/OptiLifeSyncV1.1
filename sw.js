/**
 * OptiLifeSync - PWA Service Worker (v1.0.0)
 *
 * Sağladığı Yetenekler:
 * 1. Uygulama kabuğunu (App Shell) önbelleğe alır (Hızlı mobil açılış)
 * 2. Network-First stratejisi ile güncel veri garantisi (Çevrimdışı düşüldüğünde önbellekten servis)
 * 3. Bildirim (Push Notification) olaylarını yönetir
 */

const CACHE_NAME = 'optilifesync-cache-v1';

// Önbelleğe alınacak statik kabuk dosyaları
const STATIC_ASSETS = [
    './',
    'manifest.json',
    'assets/css/sidebar.css',
    'assets/icons/icon-192.png',
    'assets/icons/icon-512.png',
    'assets/icons/apple-touch-icon.png',
    'favicon.ico',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/sweetalert2@11'
];

// 1. Kurulum (Install)
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS).catch((err) => {
                console.warn('Bazı statik varlıklar önbelleğe alınamadı:', err);
            });
        }).then(() => self.skipWaiting())
    );
});

// 2. Etkinleştirme (Activate) — Eski önbellekleri temizler
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
            );
        }).then(() => self.clients.claim())
    );
});

// 3. İstek Yakalama (Fetch)
self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Sadece GET isteklerini önbellekle
    if (request.method !== 'GET') {
        return;
    }

    // API çağrıları veya PHP sayfaları için: Önce Ağ (Network First), Hata alırsa Önbellek
    event.respondWith(
        fetch(request)
            .then((networkResponse) => {
                // Başarılı cevabı arka planda önbelleğe al
                if (networkResponse && networkResponse.status === 200 && networkResponse.type === 'basic') {
                    const responseClone = networkResponse.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(request, responseClone);
                    });
                }
                return networkResponse;
            })
            .catch(() => {
                // Çevrimdışıyken önbellekten sun
                return caches.match(request).then((cachedResponse) => {
                    if (cachedResponse) {
                        return cachedResponse;
                    }
                    // Fallback sayfası
                    if (request.headers.get('accept').includes('text/html')) {
                        return caches.match('dashboard.php') || new Response(
                            '<div style="font-family:sans-serif;padding:30px;text-align:center;background:#080f1e;color:#fff;min-height:100vh;"><h2>📱 OptiLifeSync Çevrimdışı</h2><p>İnternet bağlantınızı kontrol edip tekrar deneyin.</p></div>',
                            { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                        );
                    }
                });
            })
    );
});

// 4. Arka Plan Bildirimleri (Push Notification Event)
self.addEventListener('push', (event) => {
    let data = {};
    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            data = { title: 'OptiLifeSync', body: event.data.text() };
        }
    }

    const title = data.title || '🔔 OptiLifeSync Bildirimi';
    const options = {
        body: data.body || 'İlaç veya antrenman vaktiniz geldi!',
        icon: 'assets/icons/icon-192.png',
        badge: 'assets/icons/icon-192.png',
        vibrate: [200, 100, 200],
        tag: 'optilifesync-notification',
        data: { url: data.url || 'dashboard.php' }
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

// Bildirime tıklanınca uygulamayı aç
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const urlToOpen = event.notification.data?.url || 'dashboard.php';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            for (let client of windowClients) {
                if (client.url.includes('OptiLifeSync') || client.url.includes(urlToOpen)) {
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(urlToOpen);
            }
        })
    );
});
