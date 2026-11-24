const cachePrefix = 'augi-offline:' + self.registration.scope + ':';
const cacheName = cachePrefix + 'v1';
const offlinePage = new URL('pwa/offline.html', self.registration.scope).href;

self.addEventListener('install', event => {
    event.waitUntil((async () => {
        const response = await fetch(offlinePage, {
            credentials: 'omit',
            redirect: 'error',
            cache: 'reload'
        });
        if (!response.ok || !response.headers.get('content-type')?.startsWith('text/html')) {
            throw new Error('Página offline indisponível');
        }

        const cache = await caches.open(cacheName);
        await cache.put(offlinePage, response);
    })());
});

self.addEventListener('activate', event => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(names
            .filter(name => name.startsWith(cachePrefix) && name !== cacheName)
            .map(name => caches.delete(name)));
    })());
});

self.addEventListener('fetch', event => {
    const { request } = event;
    if (request.method !== 'GET' || request.mode !== 'navigate' ||
        !request.url.startsWith(self.registration.scope)) {
        return;
    }

    event.respondWith(fetch(request).catch(async () => {
        const cache = await caches.open(cacheName);
        return await cache.match(offlinePage) || Response.error();
    }));
});
