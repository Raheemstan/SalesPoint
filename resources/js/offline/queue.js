/*
|--------------------------------------------------------------------------
| The offline sale queue
|--------------------------------------------------------------------------
|
| An IndexedDB store of sales the cashier has taken money for but the server has
| not yet banked. This is the most dangerous data in the app: it is money that
| exists only on one device. So the rules here are narrow and deliberate.
|
|   - Nothing is ever deleted except on an explicit verdict from the server.
|     A sale leaves this queue when the server says `accepted` or `duplicate`,
|     and at no other time. Not on a timeout, not on a parse error, not on a
|     "this looks stale" heuristic. A sale that silently vanishes is a sale the
|     shop was robbed of by its own software.
|
|   - A record carries the server's answer, not just its own payload. A refused
|     sale keeps the reason, so the screen can tell a human exactly what to do
|     about it, and so it is still there tomorrow if nobody did.
|
|   - IndexedDB is used directly. No wrapper library: this file is the only
|     place in the app that touches it, the surface it needs is six operations,
|     and a till should not download a dependency to hold its own takings.
|
| THE PAYLOAD IS INTENT, NOT VALUE
|
| `claimed_total` is stored and sent so a human can compare it against what the
| server banked, and so the provisional receipt can show the customer a figure.
| The server does not read it to bank anything - every price and total is
| recomputed there. Nothing in this file should ever be written as though its
| arithmetic were authoritative.
|
| SHARED WITH THE SERVICE WORKER
|
| public/sw.js writes into this same store when a checkout POST fails at the
| network layer (JavaScript off, or a submit that outran this script). It
| re-declares the handful of constants below rather than importing them, because
| it is a static file and these are bundled - so DB_NAME, DB_VERSION, STORE and
| the record shape are a contract between the two files. Change one, change both.
*/

export const DB_NAME = 'salespoint-offline';
export const DB_VERSION = 1;
export const STORE = 'queued_sales';

/**
 * The cart as the server last rendered it, kept so that a checkout can be
 * queued from outside the page.
 *
 * The cart lives in the server session, so the only copy the device has is the
 * one Blade rendered into the cart panel. The service worker cannot read the
 * DOM, so the page mirrors that snapshot in here on every render and the worker
 * reads it when it has to queue a submit the page did not catch.
 */
export const SNAPSHOT_STORE = 'cart_snapshot';
export const SNAPSHOT_KEY = 'current';

/** Where a record is in its life. Only the server moves anything out of `held`. */
export const HELD = 'held';
export const SYNCING = 'syncing';
export const REFUSED = 'refused';

/**
 * No 0/O or 1/I: a provisional reference is read aloud across a counter and
 * written on a paper receipt, and those four characters are where that fails.
 * Same alphabet as ParkedSale::ALPHABET, for the same reason.
 */
const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/**
 * A short human-readable reference for a sale the server has not numbered yet.
 *
 * THE RECEIPT PROBLEM: an invoice number is allocated by the server, so an
 * offline sale has none at the moment the customer is standing there wanting a
 * receipt. This is what they are given instead - printed with "not yet banked"
 * on it - and the real invoice number is recorded against this reference when
 * the sale syncs, so the two can always be tied together afterwards.
 */
export function provisionalReference() {
    let code = '';
    const random = new Uint8Array(4);

    (self.crypto || window.crypto).getRandomValues(random);

    for (let i = 0; i < 4; i++) {
        code += ALPHABET[random[i] % ALPHABET.length];
    }

    return `OFF-${code}`;
}

/** A v4 UUID, which is the id the server's unique index is built on. */
export function newUuid() {
    const crypto = self.crypto || window.crypto;

    if (crypto.randomUUID) {
        return crypto.randomUUID();
    }

    // Older WebView on a cheap tablet. Same shape, same randomness source.
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

let connection = null;

/**
 * Open the database, creating the store on first use.
 *
 * The connection is kept, because opening it per operation on a slow tablet is
 * measurable, and dropped if it is ever closed under us (a version change from
 * another tab) so the next call simply reopens.
 */
export function openDb() {
    if (connection) {
        return Promise.resolve(connection);
    }

    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = () => {
            const db = request.result;

            if (!db.objectStoreNames.contains(STORE)) {
                const store = db.createObjectStore(STORE, { keyPath: 'uuid' });
                // "What is still held?" and "what was refused?" are the only two
                // questions any screen asks of this store.
                store.createIndex('state', 'state', { unique: false });
                store.createIndex('rung_up_at', 'rung_up_at', { unique: false });
            }

            if (!db.objectStoreNames.contains(SNAPSHOT_STORE)) {
                db.createObjectStore(SNAPSHOT_STORE, { keyPath: 'key' });
            }
        };

        request.onsuccess = () => {
            connection = request.result;
            connection.onclose = () => {
                connection = null;
            };
            connection.onversionchange = () => {
                connection.close();
                connection = null;
            };
            resolve(connection);
        };

        request.onerror = () => reject(request.error);
        request.onblocked = () => reject(new Error('The offline queue is locked by another tab.'));
    });
}

/** Run `work` against the store and resolve when the transaction commits. */
async function transact(mode, work) {
    const db = await openDb();
    const tx = db.transaction(STORE, mode);
    const pending = work(tx.objectStore(STORE));

    /*
     * Waited on `complete`, not on the request's own success: a write that has
     * succeeded but not committed is a write that can still be lost, and for
     * this store that means losing a sale.
     */
    await new Promise((resolve, reject) => {
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error || new Error('The offline queue write was aborted.'));
    });

    return pending;
}

const asPromise = (request) =>
    new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });

/**
 * Put a sale into the queue.
 *
 * @param {object} sale  the payload as /pos/sync expects it, minus the ids
 * @returns {Promise<object>} the stored record, including its uuid and reference
 */
export async function enqueue(sale) {
    const uuid = sale.client_uuid || newUuid();

    const record = {
        uuid,
        state: HELD,
        reference: sale.reference || provisionalReference(),
        rung_up_at: sale.rung_up_at || new Date().toISOString(),
        attempts: 0,
        last_attempt_at: null,
        // Filled in by the server's verdict, and never guessed at locally.
        reason: null,
        invoice_number: null,
        sale_id: null,
        payload: null,
    };

    record.payload = {
        ...sale,
        client_uuid: uuid,
        reference: record.reference,
        rung_up_at: record.rung_up_at,
    };

    const db = await openDb();

    await new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).put(record);
        tx.oncomplete = resolve;
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });

    return record;
}

/** Every record, newest sale first. */
export async function all() {
    const rows = (await transact('readonly', (store) => asPromise(store.getAll()))) || [];

    return rows.sort((a, b) => String(b.rung_up_at).localeCompare(String(a.rung_up_at)));
}

/** One record by uuid, or undefined. */
export async function find(uuid) {
    return transact('readonly', (store) => asPromise(store.get(uuid)));
}

/**
 * The sales worth sending, oldest first, at most `limit` of them.
 *
 * Oldest first because a queue is a queue: a sale taken at nine should reach the
 * books before one taken at noon, and a shop reading its takings back wants them
 * in the order they happened.
 *
 * A `refused` record is deliberately NOT included. Sending it again unprompted
 * would hammer the server with something that cannot succeed until a person
 * changes something, and would bury the reason under a retry counter. A human
 * presses "try again" on it.
 */
export async function dueForSync(limit = 25) {
    const rows = await all();

    return rows
        .filter((row) => row.state === HELD || row.state === SYNCING)
        .sort((a, b) => String(a.rung_up_at).localeCompare(String(b.rung_up_at)))
        .slice(0, limit);
}

/** Merge `changes` into a record. Does nothing if it has already gone. */
export async function update(uuid, changes) {
    const db = await openDb();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readwrite');
        const store = tx.objectStore(STORE);
        const get = store.get(uuid);

        get.onsuccess = () => {
            if (!get.result) {
                return;
            }

            store.put({ ...get.result, ...changes });
        };

        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

/**
 * Take a sale out of the queue.
 *
 * Only ever called with a verdict from the server in hand - `accepted` or
 * `duplicate`, both of which mean the sale is banked. There is no other caller
 * and there should not be one.
 */
export async function forget(uuid) {
    const db = await openDb();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).delete(uuid);
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

/**
 * Mirror the rendered cart, so a checkout can be queued without the DOM.
 *
 * Overwritten on every cart render, including with an empty cart, so what is
 * here is never a cart from a sale that has already been taken. See
 * SNAPSHOT_STORE above for why this exists at all.
 */
export async function rememberCart(snapshot) {
    const db = await openDb();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(SNAPSHOT_STORE, 'readwrite');
        tx.objectStore(SNAPSHOT_STORE).put({ key: SNAPSHOT_KEY, snapshot, at: new Date().toISOString() });
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

/**
 * How much is sitting on this device: what the indicator and the badge read.
 *
 * @returns {Promise<{held: number, refused: number, total: number, value: number}>}
 */
export async function depth() {
    const rows = await all();

    const held = rows.filter((row) => row.state !== REFUSED);
    const refused = rows.filter((row) => row.state === REFUSED);

    return {
        held: held.length,
        refused: refused.length,
        total: rows.length,
        // The till's own figure, shown as "about this much is not yet banked".
        // Explicitly the claimed value - the server decides the real one.
        value: rows.reduce((sum, row) => sum + Number(row.payload?.claimed_total || 0), 0),
    };
}
