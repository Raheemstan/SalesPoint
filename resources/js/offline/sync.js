/*
|--------------------------------------------------------------------------
| Replaying the queue
|--------------------------------------------------------------------------
|
| Sends held sales to /pos/sync and acts on the verdict for each one. Runs on
| `online`, on page load, after a sale is queued, and when a cashier presses
| "sync now".
|
| WHY REPLAYING TWICE AT ONCE IS SAFE, AND WHY IT IS STILL GUARDED
|
| Safe because the server is idempotent: every queued sale carries a UUID with a
| unique index behind it, so a second arrival is answered `duplicate` rather than
| banked again. That is the real guarantee and it is the one that matters, since
| it holds even against two different devices.
|
| Guarded anyway, on two levels, because "harmless" is not "free": two tabs
| replaying the same twenty-five sales doubles the work on a connection that is
| by definition bad, and it produces a duplicate attempt row for every sale,
| which clutters the audit trail a manager has to read.
|
|   - navigator.locks, which is cross-context: it covers two tabs, and it covers
|     a tab racing the service worker.
|   - an in-memory flag, for the browser (or private window) with no Web Locks.
|
| BACKOFF
|
| A till with no network must not spin. Each failed attempt pushes the next one
| further out, capped, with a little jitter so twenty tills coming back after a
| power cut do not arrive in the same millisecond. A *refused* sale is not
| retried at all - retrying cannot change a credit limit.
*/

import { HELD, SYNCING, REFUSED, dueForSync, update, forget, depth } from './queue';

const LOCK = 'salespoint-offline-sync';

/** Backoff schedule in milliseconds, then every 5 minutes. */
const BACKOFF = [2000, 5000, 15000, 60000, 300000];
const MAX_BACKOFF = 300000;

let running = false;
let timer = null;
let failures = 0;

/** Pulled out so the panel and the indicator can both listen. */
function announce(detail) {
    window.dispatchEvent(new CustomEvent('salespoint:offline-queue', { detail }));
}

/** Tell every listener what the queue now looks like. */
export async function broadcast(extra = {}) {
    try {
        announce({ ...(await depth()), online: navigator.onLine, ...extra });
    } catch (error) {
        // A queue we cannot read is worth saying so about, but not worth throwing over.
        announce({ error: 'The offline queue could not be read on this device.', ...extra });
    }
}

/**
 * Hold the sync lock for the duration of `work`, or do nothing if someone else
 * has it.
 *
 * `ifAvailable` rather than waiting: if another context is already replaying,
 * this call has nothing useful to add, and queueing up behind it would mean a
 * burst of `online` events turning into a burst of identical batches.
 */
async function withLock(work) {
    if (running) {
        return { skipped: true };
    }

    running = true;

    try {
        if (navigator.locks?.request) {
            return await navigator.locks.request(LOCK, { ifAvailable: true }, async (lock) =>
                lock ? work() : { skipped: true },
            );
        }

        return await work();
    } finally {
        running = false;
    }
}

/**
 * Send what is held and apply the verdicts.
 *
 * @param {object} config  { syncUrl, token, batch }
 * @returns {Promise<object>} a short summary for the caller to show
 */
export async function replay(config) {
    return withLock(async () => {
        const pending = await dueForSync(config.batch || 25);

        if (pending.length === 0) {
            failures = 0;
            await broadcast();

            return { sent: 0, accepted: 0, rejected: 0, duplicate: 0 };
        }

        if (!navigator.onLine) {
            // Nothing is marked as attempted: this is not a failure, it is a
            // device that knows it has no line.
            await broadcast();

            return { sent: 0, offline: true };
        }

        const now = new Date().toISOString();

        await Promise.all(
            pending.map((record) =>
                update(record.uuid, {
                    state: SYNCING,
                    attempts: (record.attempts || 0) + 1,
                    last_attempt_at: now,
                }),
            ),
        );

        let response;

        try {
            response = await fetch(config.syncUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': config.token,
                    // Tells the service worker this request is already being
                    // looked after here, so it must not queue it a second time.
                    'X-Offline-Handled': '1',
                },
                body: JSON.stringify({ sales: pending.map((record) => record.payload) }),
            });
        } catch (error) {
            // The line went down again mid-batch. Put everything back to held -
            // the sales are untouched, and the server either saw none of them or
            // will answer `duplicate` for the ones it did.
            await Promise.all(pending.map((record) => update(record.uuid, { state: HELD })));

            return await fail(config, 'The till still cannot reach the server.');
        }

        if (response.status === 419 || response.status === 401) {
            /*
             * The session expired while the till was offline. Nothing is wrong
             * with the queue and nothing may be dropped - the cashier signs in
             * again and the same sales go up untouched.
             */
            await Promise.all(pending.map((record) => update(record.uuid, { state: HELD })));
            await broadcast({ message: 'Sign in again to send the held sales up.', variant: 'warning' });

            return { sent: 0, signedOut: true };
        }

        if (!response.ok) {
            await Promise.all(pending.map((record) => update(record.uuid, { state: HELD })));

            const body = await response.json().catch(() => ({}));

            return await fail(config, body.message || 'The server refused the batch of held sales.');
        }

        const body = await response.json().catch(() => ({}));
        const results = Array.isArray(body.results) ? body.results : [];

        const counts = { sent: pending.length, accepted: 0, rejected: 0, duplicate: 0, notes: [] };

        for (const verdict of results) {
            const record = pending.find((row) => row.uuid === verdict.client_uuid);

            if (!record) {
                continue;
            }

            if (verdict.status === 'accepted' || verdict.status === 'duplicate') {
                /*
                 * Banked. This is the only thing that takes a sale out of the
                 * queue. The invoice number is announced first so the panel can
                 * show "OFF-A4K9 is now INV-...", which is what ties the
                 * provisional receipt the customer is holding to the real one.
                 */
                counts[verdict.status] += 1;

                if (Array.isArray(verdict.notes) && verdict.notes.length) {
                    counts.notes.push(...verdict.notes);
                }

                announce({
                    banked: {
                        reference: record.reference,
                        invoice_number: verdict.invoice_number,
                        status: verdict.status,
                        notes: verdict.notes || [],
                    },
                });

                await forget(record.uuid);
                continue;
            }

            // Refused. Kept, with the reason, and not retried on its own.
            counts.rejected += 1;

            await update(record.uuid, {
                state: REFUSED,
                reason: verdict.reason || 'The server refused this sale but did not say why.',
            });
        }

        /*
         * Any sale the server did not mention goes back to held rather than
         * being assumed one way or the other. It will be sent again, and if it
         * was in fact banked the answer will be `duplicate`.
         */
        const answered = new Set(results.map((verdict) => verdict.client_uuid));

        await Promise.all(
            pending
                .filter((record) => !answered.has(record.uuid))
                .map((record) => update(record.uuid, { state: HELD })),
        );

        failures = 0;
        await broadcast({ synced: counts, status: body.status });

        // More waiting? Go round again - the batch is capped server-side.
        if ((await dueForSync(1)).length > 0) {
            schedule(config, 1000);
        }

        return counts;
    });
}

/** Record a failed attempt, tell the screen, and arrange the next try. */
async function fail(config, message) {
    failures += 1;
    await broadcast({ error: message });
    schedule(config);

    return { sent: 0, error: message };
}

/**
 * Arrange the next attempt, replacing any already arranged.
 *
 * Jittered so a row of tills coming back after a power cut do not all hit the
 * server in the same instant.
 */
export function schedule(config, delay = null) {
    if (timer) {
        clearTimeout(timer);
    }

    const base = delay ?? (BACKOFF[Math.min(failures, BACKOFF.length - 1)] || MAX_BACKOFF);
    const wait = base + Math.floor(Math.random() * Math.min(base, 2000));

    timer = setTimeout(() => {
        timer = null;
        replay(config);
    }, wait);
}

/** Send one refused sale again, because a person asked. */
export async function retry(uuid, config) {
    await update(uuid, { state: HELD, reason: null });

    return replay(config);
}
