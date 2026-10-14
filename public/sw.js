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
| stale).
|
| ONE DELIBERATE EXCEPTION: POST /pos/checkout
|
| The rule below used to be "non-GET requests are never intercepted", so a
| checkout always reached the server or failed loudly. That guarantee still holds
| for every non-GET request in the app except one, and the exception is narrow on
| purpose: the exact path `/pos/checkout`, and only when the request **fails at
| the network layer**.
|
| Why it had to give: a checkout is a form post that navigates. If the line drops
| mid-request, the browser shows an error page and the sale is gone - but the
| cashier has already taken the money and handed over the goods. Nothing inside
| the page can recover a navigation that has already failed; a service worker is
| the only thing that sees the failure and can still answer. So instead of
| failing loudly it fails *honestly*: the sale is written into the same IndexedDB
| queue the page uses, and the cashier gets a page that says the sale is held on
| this device, is not yet banked, and carries a provisional reference.
|
| What has NOT changed, and must not:
|
|   - A request that the server *answers* is never touched. A 422, a 419, a 500
|     are passed straight through. Queueing a sale the server refused would leave
|     the cashier believing it was taken, which is worse than any error page.
|   - Nothing is retried from here. The worker only ever enqueues; replaying is
|     the page's job (resources/js/offline/sync.js), under a lock, and the server
|     is idempotent so a double replay banks once.
|   - Every other non-GET request - every other POST, PUT, DELETE anywhere in the
|     app - is still returned untouched, two lines into the fetch handler.
|   - A submit the page already took over carries `X-Offline-Handled` and is left
|     alone, so one sale can never be queued twice.
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

    /*
     * Never touch anything that changes data, with the single exception
     * documented at the top of this file: a checkout that cannot reach the
     * server at all. Everything else must reach the server or fail loudly - it
     * must not be answered, retried or queued from here.
     */
    if (request.method !== 'GET') {
        if (isCheckout(request)) {
            event.respondWith(checkoutOrHold(request));
        }

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

/*
|--------------------------------------------------------------------------
| Offline sale queueing
|--------------------------------------------------------------------------
|
| The exception described at the top of this file. The rules it keeps:
|
|   - this path only, `/pos/checkout`, resolved against the worker's scope so an
|     install in a subdirectory behaves the same;
|   - only a request that FAILS - a server answer of any status is passed
|     through untouched;
|   - a submit the page already took over is skipped, so nothing is queued twice;
|   - enqueue only. Replaying belongs to the page, which holds a cross-context
|     lock while it does it, and the server is idempotent either way.
|
| THE STORE IS SHARED WITH THE BUNDLE
|
| resources/js/offline/queue.js owns this schema. This file is static and that
| one is bundled, so it cannot import it: the four constants and the record shape
| below are a copy, and they are a contract. Change one, change both.
*/

const QUEUE_DB = 'salespoint-offline';
const QUEUE_DB_VERSION = 1;
const QUEUE_STORE = 'queued_sales';
const SNAPSHOT_STORE = 'cart_snapshot';
const SNAPSHOT_KEY = 'current';

const CHECKOUT_URL = scoped('pos/checkout');

/** Same alphabet as ParkedSale and the bundle: no 0/O or 1/I, for reading aloud. */
const REFERENCE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

const isCheckout = (request) => {
    const url = new URL(request.url);

    return (
        request.method === 'POST' &&
        url.origin === self.location.origin &&
        // Exact path. Not startsWith: nothing else under /pos is covered.
        url.origin + url.pathname === CHECKOUT_URL &&
        // The page is already looking after this one.
        request.headers.get('X-Offline-Handled') !== '1'
    );
};

/**
 * Try the checkout. If the server answers, hand that answer back exactly as it
 * came. If the request cannot be made at all, hold the sale and say so.
 */
async function checkoutOrHold(request) {
    // Cloned before the body is read: a Request body can only be consumed once,
    // and the clone is what gets sent.
    const attempt = request.clone();

    try {
        return await fetch(attempt);
    } catch (err) {
        // The network, not the server. Everything below is about not losing the money.
    }

    let record = null;

    try {
        record = await holdSale(request);
    } catch (error) {
        record = null;
    }

    return heldPage(record);
}

/** Write the submitted checkout into the queue the page also reads. */
async function holdSale(request) {
    const form = await request.formData();
    const snapshot = await mirroredCart();

    if (!snapshot || !Array.isArray(snapshot.lines) || snapshot.lines.length === 0) {
        // No copy of the cart, so there is nothing honest to queue. Answered as
        // a failure rather than as a held sale - see heldPage().
        return null;
    }

    const uuid = newUuid();
    const reference = provisionalReference();
    const rungUpAt = new Date().toISOString();

    const tenders = readTenders(form);
    const total = Number(snapshot.totals?.grand_total || 0);
    const tendered = tenders.reduce((sum, tender) => sum + Number(tender.amount || 0), 0);
    const p2 = (value) => Math.round(value * 100) / 100;

    const record = {
        uuid,
        state: 'held',
        reference,
        rung_up_at: rungUpAt,
        attempts: 0,
        last_attempt_at: null,
        reason: null,
        invoice_number: null,
        sale_id: null,
        payload: {
            client_uuid: uuid,
            reference,
            rung_up_at: rungUpAt,
            cashier_id: snapshot.cashier_id ?? null,
            shift_id: snapshot.shift_id ?? null,
            lines: snapshot.lines,
            order_discount: snapshot.order_discount || null,
            service_charge: snapshot.service_charge || null,
            tenders,
            customer_id: form.get('customer_id') ? Number(form.get('customer_id')) : null,
            customer_name: snapshot.customer_name || null,
            on_credit: form.get('on_credit') === '1',
            due_date: form.get('due_date') || null,
            // The till's own figure, never read by the server when it banks.
            claimed_total: total,
            totals: {
                ...(snapshot.totals || {}),
                change: Math.max(0, p2(tendered - total)),
                balance_due: Math.max(0, p2(total - tendered)),
            },
        },
    };

    await put(QUEUE_STORE, record);

    // Tell any open till so its indicator and held list are right immediately.
    const clients = await self.clients.matchAll({ type: 'window' });
    clients.forEach((client) => client.postMessage({ type: 'OFFLINE_SALE_QUEUED', reference }));

    return record;
}

/** The split rows if the cashier was splitting, otherwise the single tender. */
function readTenders(form) {
    const rows = [];

    for (let i = 0; i < 4; i++) {
        const method = form.get(`tenders[${i}][method]`);

        if (method) {
            rows.push({
                method,
                amount: Number(form.get(`tenders[${i}][amount]`) || 0),
                reference: form.get(`tenders[${i}][reference]`) || null,
            });
        }
    }

    if (rows.length) {
        return rows;
    }

    return [
        {
            method: form.get('payment_method') || 'cash',
            amount: Number(form.get('paid_amount') || 0),
            reference: form.get('payment_reference') || null,
        },
    ];
}

/**
 * The page the cashier gets instead of a browser error.
 *
 * Entirely self-contained - the device has no network, so it can fetch no
 * stylesheet - and `no-store`, because a "sale held" page must never be served
 * again to a later checkout.
 */
function heldPage(record) {
    const held = record !== null;

    const body = held
        ? `<h1>Held on this till</h1>
           <p class="ref">${escapeHtml(record.reference)}</p>
           <p><strong>This sale is not yet banked.</strong> It is stored on this device and will be
           sent up automatically when the connection returns. Give the customer the reference above.</p>`
        : `<h1>This sale was not recorded</h1>
           <p><strong>Do not hand over the goods.</strong> The till could not reach the server and could
           not hold the sale either, because it has no copy of the basket. Ring it up again once the
           connection is back.</p>`;

    return new Response(
        `<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${held ? 'Sale held' : 'Sale not recorded'}</title>
<style>
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
         font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif; background:#f8fafc; color:#0f172a; padding:24px; }
  .card { max-width:30rem; background:#fff; border-radius:16px; padding:28px;
          box-shadow:0 1px 3px rgba(0,0,0,.1); border-top:6px solid ${held ? '#d97706' : '#dc2626'}; }
  h1 { margin:0 0 12px; font-size:20px; }
  .ref { font:700 28px/1 ui-monospace,"Courier New",monospace; letter-spacing:2px; margin:0 0 16px; }
  a { display:inline-block; margin-top:20px; min-height:44px; line-height:44px; padding:0 20px;
      border-radius:10px; background:#0f172a; color:#fff; text-decoration:none; font-weight:600; }
</style></head>
<body><div class="card">${body}<a href="${escapeHtml(scoped('pos'))}">Back to the till</a></div></body></html>`,
        {
            status: held ? 202 : 503,
            headers: {
                'Content-Type': 'text/html; charset=utf-8',
                'Cache-Control': 'no-store',
            },
        },
    );
}

const escapeHtml = (value) =>
    String(value ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[c]);

function provisionalReference() {
    const random = new Uint8Array(4);
    self.crypto.getRandomValues(random);

    let code = '';

    for (let i = 0; i < 4; i++) {
        code += REFERENCE_ALPHABET[random[i] % REFERENCE_ALPHABET.length];
    }

    return `OFF-${code}`;
}

function newUuid() {
    if (self.crypto.randomUUID) {
        return self.crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);
    self.crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** Open the shared queue database. The schema belongs to resources/js/offline/queue.js. */
function openQueueDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(QUEUE_DB, QUEUE_DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;

            if (!db.objectStoreNames.contains(QUEUE_STORE)) {
                const store = db.createObjectStore(QUEUE_STORE, { keyPath: 'uuid' });
                store.createIndex('state', 'state', { unique: false });
                store.createIndex('rung_up_at', 'rung_up_at', { unique: false });
            }

            if (!db.objectStoreNames.contains(SNAPSHOT_STORE)) {
                db.createObjectStore(SNAPSHOT_STORE, { keyPath: 'key' });
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
        request.onblocked = () => reject(new Error('The offline queue is locked.'));
    });
}

/** Write one record, resolving only once the transaction has committed. */
async function put(storeName, record) {
    const db = await openQueueDb();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(storeName, 'readwrite');
        tx.objectStore(storeName).put(record);
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

/** The cart as the till last rendered it. See SNAPSHOT_STORE in queue.js. */
async function mirroredCart() {
    const db = await openQueueDb();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(SNAPSHOT_STORE, 'readonly');
        const get = tx.objectStore(SNAPSHOT_STORE).get(SNAPSHOT_KEY);

        get.onsuccess = () => resolve(get.result?.snapshot || null);
        get.onerror = () => reject(get.error);
    });
}
