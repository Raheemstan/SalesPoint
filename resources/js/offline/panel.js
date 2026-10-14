/*
|--------------------------------------------------------------------------
| The offline indicator and the held-sales panel
|--------------------------------------------------------------------------
|
| An Alpine component for resources/views/pos/offline.blade.php. Three jobs:
|
|   - say whether the till has a line, always, without being asked;
|   - say how many sales are sitting on this device and are not yet money;
|   - make a refused sale impossible to ignore.
|
| ON THAT LAST POINT
|
| A refusal is the one state here that needs a person. So it is not a badge: the
| panel opens itself when a refusal arrives, the banner stays red while any
| refusal exists, and nothing in this file can clear one - only the server
| accepting the sale on a later attempt can, by which point it is banked. There
| is deliberately no "dismiss" on a refusal.
|
| The server-side list is rendered into the page by Blade as well, so a refused
| sale is visible even with this script broken, and a refusal recorded against a
| device that has since been wiped is still visible on every other till.
*/

import { all, find, REFUSED } from './queue';
import { offlineConfig } from './config';
import { replay, retry } from './sync';
import { printProvisionalReceipt } from './receipt';

export default function offlineQueue(given = null) {
    const config = given || offlineConfig() || {};

    return {
        config,
        online: navigator.onLine,
        held: [],
        refused: [],
        /*
         * The shop's own count of refused offline sales, which can be larger
         * than this device's: a refusal outlives the till that caused it, and
         * another till's refusals are a manager's business too. Blade renders
         * the list itself at page load; this is kept current so the panel can
         * say when that rendered list has gone stale.
         */
        serverRejectedCount: config.serverRejectedCount || 0,
        renderedRejectedCount: config.serverRejectedCount || 0,
        open: false,
        busy: false,
        message: null,
        messageVariant: 'info',
        // "OFF-A4K9 is now INV-..." - how a customer's provisional slip is tied
        // to the real invoice. Kept on screen until the cashier closes the panel.
        reconciled: [],

        get queued() {
            return this.held.length + this.refused.length;
        },

        /** Has the shop's record changed since this page was rendered? */
        get recordIsStale() {
            return this.serverRejectedCount > this.renderedRejectedCount;
        },

        /** The figure on the indicator: unbanked money, by the till's own reckoning. */
        get value() {
            return this.money(
                [...this.held, ...this.refused].reduce(
                    (sum, record) => sum + Number(record.payload?.claimed_total || 0),
                    0,
                ),
            );
        },

        money(amount) {
            return (
                this.config.symbol +
                Number(amount || 0).toLocaleString(undefined, {
                    minimumFractionDigits: this.config.decimals,
                    maximumFractionDigits: this.config.decimals,
                })
            );
        },

        when(iso) {
            if (!iso) {
                return '';
            }

            const at = new Date(iso);

            return Number.isNaN(at.getTime()) ? String(iso) : at.toLocaleString();
        },

        init() {
            window.addEventListener('online', () => {
                this.online = true;
                this.note('The connection is back. Sending held ' + this.config.salesTerm.toLowerCase() + ' up.', 'info');
                this.sync();
            });

            window.addEventListener('offline', () => {
                this.online = false;
                this.note('The till is offline. ' + this.config.saleTerm + 's will be held on this device.', 'warning');
            });

            // Every change to the queue, from anywhere - this tab, another tab,
            // the service worker's own enqueue - comes through here.
            window.addEventListener('salespoint:offline-queue', (event) => this.absorb(event.detail || {}));

            // A sale queued by the in-page interceptor.
            window.addEventListener('salespoint:offline-queued', () => this.refresh());

            this.refresh();

            // On load, because a till is usually reopened after the outage that
            // filled the queue rather than during it.
            if (navigator.onLine) {
                this.sync();
            }
        },

        /** Re-read the device's queue. */
        async refresh() {
            try {
                const rows = await all();

                this.held = rows.filter((row) => row.state !== REFUSED);
                this.refused = rows.filter((row) => row.state === REFUSED);
            } catch (error) {
                this.note('This device\'s offline queue could not be read.', 'error');
            }

            if (this.refused.length > 0) {
                // Opened, not badged. See the file header.
                this.open = true;
            }
        },

        /** Fold in whatever the sync engine just announced. */
        absorb(detail) {
            if (detail.banked) {
                this.reconciled.unshift(detail.banked);
                this.reconciled = this.reconciled.slice(0, 20);
            }

            if (detail.status) {
                this.serverRejectedCount = detail.status.rejected ?? this.serverRejectedCount;
            }

            if (detail.error) {
                this.note(detail.error, 'error');
            }

            if (detail.message) {
                this.note(detail.message, detail.variant || 'info');
            }

            if (detail.synced) {
                const { accepted, duplicate, rejected } = detail.synced;

                if (accepted + duplicate > 0) {
                    this.note(
                        `${accepted + duplicate} held ${this.config.salesTerm.toLowerCase()} banked.` +
                            (rejected > 0 ? ` ${rejected} refused - see below.` : ''),
                        rejected > 0 ? 'warning' : 'success',
                    );
                }
            }

            if (typeof detail.online === 'boolean') {
                this.online = detail.online;
            }

            this.refresh();
        },

        note(message, variant = 'info') {
            this.message = message;
            this.messageVariant = variant;

            // Toasted as well, because the panel may well be shut.
            if (window.toast) {
                window.toast(message, { variant: variant === 'info' ? 'info' : variant });
            }
        },

        /** "Sync now". */
        async sync() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                await replay(this.syncConfig());
            } finally {
                this.busy = false;
            }

            await this.refreshStatus();
        },

        /** Ask the server what is outstanding, for the server-side list. */
        async refreshStatus() {
            if (!navigator.onLine) {
                return;
            }

            try {
                const response = await fetch(this.config.statusUrl, {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    return;
                }

                this.serverRejectedCount = (await response.json()).rejected ?? 0;
            } catch (error) {
                /* offline again; the rendered list stands */
            }
        },

        /** Send one refused sale again, after a person has fixed the cause. */
        async retryOne(uuid) {
            this.busy = true;

            try {
                await retry(uuid, this.syncConfig());
            } finally {
                this.busy = false;
            }

            await this.refresh();
        },

        /** Print the provisional slip again - a customer lost theirs, or it was blocked. */
        async reprint(uuid) {
            const record = await find(uuid);

            if (!record) {
                this.note('That held ' + this.config.saleTerm.toLowerCase() + ' is no longer on this device.', 'warning');

                return;
            }

            if (!printProvisionalReceipt(record, this.config)) {
                this.note('The browser blocked the receipt window. Allow pop-ups for this till.', 'warning');
            }
        },

        syncConfig() {
            return {
                syncUrl: this.config.syncUrl,
                token: document.querySelector('meta[name=csrf-token]')?.content,
                batch: this.config.batch,
            };
        },
    };
}
