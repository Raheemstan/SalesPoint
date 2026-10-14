/*
|--------------------------------------------------------------------------
| Catching a checkout that cannot reach the server
|--------------------------------------------------------------------------
|
| The till's checkout is an ordinary form post that navigates. With no network
| that navigation fails and the cashier - who has already taken the money and
| put the goods in a bag - gets a browser error page. Two things catch it:
|
|   1. This file, when the device already knows it is offline. The submit is
|      taken over before the browser starts a navigation it cannot finish, the
|      sale is queued, and a provisional receipt is printed. No request is made.
|
|   2. public/sw.js, when the browser believed it was online and the request
|      died anyway. That is the only place able to catch a failed form
|      navigation after the fact.
|
| WHAT THIS FILE DELIBERATELY DOES NOT DO
|
| It does not take over a submit while the device is online. It would have to
| post with fetch and then navigate to the result, and that second navigation
| re-reads a session whose flashed `error` the fetch has already consumed - so a
| refused checkout (insufficient payment, a discount over the limit) would show
| the cashier nothing at all. The online path is left exactly as it was: form
| posts, server answers, page renders.
|
| It also never queues a sale the server has *answered*. A 422, a 419 or a 500
| mean the server heard us and said no; queueing that would leave the cashier
| believing a refused sale was taken, which is worse than any error page.
|
| WHAT IS QUEUED
|
| Intent, from the form and from the cart snapshot the server rendered into the
| page: products, quantities, the discounts applied, the tenders, the customer,
| the shift, the moment. The totals travel too, as `claimed_total` and the
| figures for the provisional receipt, and the server ignores them when it banks
| the sale.
*/

import { enqueue, rememberCart } from './queue';
import { printProvisionalReceipt } from './receipt';

/** Read the cart as the server last rendered it into the page. */
export function cartSnapshot() {
    const node = document.getElementById('offline-cart-snapshot');

    if (!node) {
        return null;
    }

    try {
        return JSON.parse(node.textContent);
    } catch (error) {
        return null;
    }
}

/**
 * Keep the mirrored copy in step with the screen.
 *
 * The cart panel is replaced wholesale on every change (see the till's
 * reloadCart), which raises `salespoint:cart-rendered`, so this runs then as
 * well as on first load. An empty cart is written too - the mirror must never
 * hold a cart belonging to a sale that has already been taken.
 */
export function mirrorCart() {
    const snapshot = cartSnapshot();

    return rememberCart(snapshot || { lines: [], totals: {} }).catch(() => {
        /* storage denied: the in-page path still works, the worker's does not */
    });
}

/**
 * Turn the checkout form into the tenders the sync endpoint expects.
 *
 * Read out of the submitted form rather than out of Alpine's state, so what is
 * queued is exactly what would have been posted - including which payment mode
 * the cashier was in, because the hidden split rows only exist in one of them.
 */
function tendersFrom(data) {
    const rows = [];

    for (let i = 0; i < 4; i++) {
        const method = data.get(`tenders[${i}][method]`);

        if (method) {
            rows.push({
                method,
                amount: Number(data.get(`tenders[${i}][amount]`) || 0),
                reference: data.get(`tenders[${i}][reference]`) || null,
            });
        }
    }

    if (rows.length) {
        return rows;
    }

    return [
        {
            method: data.get('payment_method') || 'cash',
            amount: Number(data.get('paid_amount') || 0),
            reference: data.get('payment_reference') || null,
        },
    ];
}

/**
 * Build the queued payload from a submitted checkout form.
 *
 * Exported and given the snapshot explicitly so the same shape is built whether
 * it comes from this page or from the service worker's mirrored copy.
 *
 * @returns {object|null} null when there is no cart to build it from
 */
export function payloadFrom(formData, snapshot, config) {
    if (!snapshot || !Array.isArray(snapshot.lines) || snapshot.lines.length === 0) {
        return null;
    }

    const tenders = tendersFrom(formData);
    const tendered = tenders.reduce((sum, tender) => sum + Number(tender.amount || 0), 0);
    const total = Number(snapshot.totals?.grand_total || 0);
    const p2 = (value) => Math.round(value * 100) / 100;

    return {
        rung_up_at: new Date().toISOString(),
        cashier_id: config.cashierId,
        // The drawer this was rung up into. The server stamps it only if that
        // shift is still open - see OfflineSyncService::shiftFor.
        shift_id: config.shiftId,
        lines: snapshot.lines,
        order_discount: snapshot.order_discount || null,
        service_charge: snapshot.service_charge || null,
        tenders,
        customer_id: formData.get('customer_id') ? Number(formData.get('customer_id')) : null,
        customer_name: snapshot.customer_name || null,
        on_credit: formData.get('on_credit') === '1',
        due_date: formData.get('due_date') || null,
        /*
         * What the till told the customer. Sent so a human can compare it with
         * what was banked, and so the provisional receipt has figures on it.
         * The server does not read it to decide anything.
         */
        claimed_total: total,
        totals: {
            ...(snapshot.totals || {}),
            change: Math.max(0, p2(tendered - total)),
            balance_due: Math.max(0, p2(total - tendered)),
        },
    };
}

/**
 * Intercept the till's checkout form when the device knows it is offline.
 *
 * Delegated from the document, in the capture phase, because the cart panel and
 * the form inside it are replaced every time the cart changes, and because this
 * has to run before the page's own handlers.
 *
 * @param {object} config
 * @param {(record: object) => void} onQueued
 */
export function interceptCheckout(config, onQueued) {
    document.addEventListener(
        'submit',
        async (event) => {
            if (!event.target.matches('#checkout-form') || navigator.onLine) {
                return;
            }

            event.preventDefault();
            // Stopped here: nothing downstream should also try to take this
            // money, and the sale is about to be queued exactly once.
            event.stopImmediatePropagation();

            await queueSale(new FormData(event.target), config, onQueued);
        },
        true,
    );
}

/** Queue the sale this form was about, and say so loudly. */
export async function queueSale(formData, config, onQueued) {
    const sale = config.saleTerm.toLowerCase();
    const payload = payloadFrom(formData, cartSnapshot(), config);

    if (!payload) {
        /*
         * No cart to queue. Said as bluntly as possible: the one outcome that
         * must never happen is a cashier believing a sale was recorded when it
         * was not. A timeout of 0 keeps it on screen until it is dismissed.
         */
        window.toast(
            `This ${sale} cannot be held: the till has no copy of the cart. It has NOT been recorded - do not hand over the goods until the connection returns.`,
            { variant: 'error', timeout: 0 },
        );

        return null;
    }

    let record;

    try {
        record = await enqueue(payload);
    } catch (error) {
        // The queue itself failed - a private window, or storage denied.
        window.toast(
            `This device cannot hold the ${sale} offline. It has NOT been recorded - write it down and ring it up when the connection returns.`,
            { variant: 'error', timeout: 0 },
        );

        return null;
    }

    // Something for the customer standing there, carrying the provisional reference.
    const printed = printProvisionalReceipt(record, config);

    window.toast(
        `Held on this device as ${record.reference}. It is NOT yet banked, and will be sent up when the connection returns.` +
            (printed ? '' : ' The receipt window was blocked - print it again from the held list.'),
        { variant: 'warning', timeout: 15000 },
    );

    if (onQueued) {
        onQueued(record);
    }

    return record;
}
