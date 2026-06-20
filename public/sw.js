const CACHE_NAME = 'stock-platform-static-v2';
const STATIC_ASSETS = ['/manifest.json', '/favicon.ico'];

function isCacheableStaticRequest(request) {
    const url = new URL(request.url);

    if (url.origin !== self.location.origin || request.method !== 'GET') {
        return false;
    }

    return url.pathname.startsWith('/build/') || STATIC_ASSETS.includes(url.pathname);
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE_NAME)
            .then((cache) => cache.addAll(STATIC_ASSETS))
            .catch(() => undefined),
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))),
        ),
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    if (!isCacheableStaticRequest(event.request)) {
        return;
    }

    event.respondWith(
        caches.match(event.request).then((cached) => {
            if (cached) {
                return cached;
            }

            return fetch(event.request).then((response) => {
                if (response.ok) {
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, response.clone()));
                }

                return response;
            });
        }),
    );
});
