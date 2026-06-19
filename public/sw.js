const CACHE_NAME = 'roquette-v1';
const STATIC_ASSETS = [
    '/',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS);
        })
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name !== CACHE_NAME)
                    .map((name) => caches.delete(name))
            );
        })
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Skip Mercure/SSE requests, emojis, non-GET requests, or external requests
    if (
        event.request.method !== 'GET' ||
        !url.origin.startsWith(self.location.origin) ||
        url.pathname.startsWith('/.well-known/mercure') ||
        url.pathname.startsWith('/emojis/') ||
        (event.request.headers.get('Accept') && event.request.headers.get('Accept').includes('text/event-stream'))
    ) {
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                const cloned = response.clone();
                caches.open(CACHE_NAME).then((cache) => {
                    cache.put(event.request, cloned);
                });
                return response;
            })
            .catch(() => {
                return caches.match(event.request).then((cached) => {
                    return cached || Response.error();
                });
            })
    );
});
