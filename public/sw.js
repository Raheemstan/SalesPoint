/*
|--------------------------------------------------------------------------
| SalesPoint service worker
|--------------------------------------------------------------------------
|
| Caches the app shell and static assets so the till starts and looks right
| with no internet. It deliberately does NOT cache pages or API responses:
|
|   - a cached page could be shown to the wrong signed-in user;
|   - a cached /pos or /sales page would show stale stock and stale takings,
|     which at a till is worse than an honest "you are offline" screen.
|
| So navigations are network-first and fall back to offline.html, while hashed
| build assets are cache-first (a new build is a new URL, so they can never go
| stale). Offline sale queueing and sync are explicitly out of scope.
|
| Versioning: resources/js/pwa.js registers this file as /sw.js?v=<token>,
| where the token is derived from the Vite manifest. A changed URL installs a
| new worker, the cache names below pick up the new token, and activate() drops
| every cache that does not match. Nothing here has to be edited per deploy.
|
*/

const VERSION = new URL(self.location.href).searchParams.get('v') || 'dev';
const SHELL_CACHE = `salespoint-shell-${VERSION}`;
const ASSET_CACHE = `salespoint-assets-${VERSION}`;
const OWNED = [SHELL_CACHE, ASSET_CACHE];

// Resolved against the worker's scope so an install in a subdirectory works.
const scoped = (path) => new URL(path, self.registration.scope).toString();

const OFFLINE_URL = scoped('offline.html');

// The handful of files needed to render something sensible with no network.
const SHELL = [
    OFFLINE_URL,
    scoped('manifest.json'),
    scoped('favicon.ico'),
    scoped('icons/icon-192.png'),
    scoped('icons/icon-512.png'),
    scoped('icons/maskable-512.png'),
];

// Static things worth keeping even though Vite did not hash them. Note the
// absence of a bare `.json`: an API answer must not be cached just because of
// its extension, so manifest.json is named outright instead.
const STATIC_EXTENSIONS = [
    '.css', '.js', '.mjs', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp',
    '.ico', '.woff', '.woff2', '.ttf', '.otf',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const cache = await caches.open(SHELL_CACHE);

            // `reload` so a reinstall cannot pick the old copies back out of
            // the HTTP cache. Individually, because one missing icon must not
            // fail the whole install (addAll is all-or-nothing).
            await Promise.all(
                SHELL.map((url) =>
                    cache.add(new Request(url, { cache: 'reload' })).catch(() => {}),
                ),
            );

            // Take over straight away: the alternative is waiting for every tab
            // to close, which on a till that is open all day means a deploy
            // never lands.
            await self.skipWaiting();
        })(),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const names = await caches.keys();

            await Promise.all(
                names
                    .filter((name) => name.startsWith('salespoint-') && !OWNED.includes(name))
                    .map((name) => caches.delete(name)),
            );

            await self.clients.claim();
        })(),
    );
});

/*
| The page posts the hashed CSS/JS it is actually using (see resources/js/pwa.js).
| Reading them off the document beats parsing the build manifest here, and it is
| always the current build.
*/
self.addEventListener('message', (event) => {
    const { type, assets } = event.data || {};

    if (type === 'SKIP_WAITING') {
        self.skipWaiting();
        return;
    }

    if (type !== 'PRECACHE' || !Array.isArray(assets)) {
        return;
    }

    event.waitUntil(
        (async () => {
            const cache = await caches.open(ASSET_CACHE);

            await Promise.all(
                assets.map(async (url) => {
                    if (await cache.match(url)) {
                        return;
                    }

                    await cache.add(new Request(url, { cache: 'reload' })).catch(() => {});
                }),
            );
        })(),
    );
});

const isStaticAsset = (url) =>
    url.pathname.startsWith('/build/') ||
    url.pathname.startsWith('/icons/') ||
    url.pathname.endsWith('/manifest.json') ||
    STATIC_EXTENSIONS.some((ext) => url.pathname.endsWith(ext));

const cacheable = (response) =>
    response &&
    response.status === 200 &&
    response.type !== 'opaque' &&
    !(response.headers.get('cache-control') || '').includes('no-store');

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Never touch anything that changes data. A checkout must reach the server
    // or fail loudly - it must not be answered, retried or queued from here.
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Other origins, non-http schemes and range requests are left alone.
    if (url.origin !== self.location.origin || !url.protocol.startsWith('http')) {
        return;
    }

    if (request.headers.has('range')) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(networkThenOfflinePage(request));
        return;
    }

    if (isStaticAsset(url)) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // Everything else (page fragments, /pos/search, exports) goes straight to
    // the network and is never cached.
});

/**
 * Pages: always try the network, and show the offline page if it is not there.
 * Nothing is cached, so a page can never be served to the wrong user.
 */
async function networkThenOfflinePage(request) {
    try {
        return await fetch(request);
    } catch (err) {
        const cached = await caches.match(OFFLINE_URL, { ignoreSearch: true });

        return (
            cached ||
            new Response('<h1>Offline</h1><p>SalesPoint cannot reach the server.</p>', {
                status: 503,
                headers: { 'Content-Type': 'text/html; charset=utf-8' },
            })
        );
    }
}

/**
 * Assets: serve from the cache when we have them, otherwise fetch and keep.
 * Safe to cache forever because Vite puts a content hash in the filename.
 */
async function cacheFirst(request) {
    // Look in every cache we own, so a file precached at install time (the
    // logo, an icon) is found as readily as one picked up at runtime.
    const cached = await caches.match(request);

    if (cached) {
        return cached;
    }

    try {
        const response = await fetch(request);

        if (cacheable(response)) {
            const cache = await caches.open(ASSET_CACHE);
            cache.put(request, response.clone());
        }

        return response;
    } catch (err) {
        return new Response('', { status: 504, statusText: 'Offline' });
    }
}
