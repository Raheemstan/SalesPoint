/*
|--------------------------------------------------------------------------
| Offline sales: wiring
|--------------------------------------------------------------------------
|
| Pulls the four pieces together and starts them, if and only if the page is a
| till. Everything is keyed off the `#offline-queue` element that
| resources/views/pos/offline.blade.php renders, so no other page in the app
| pays anything for this feature.
|
| The config comes from the server, in a JSON script tag: URLs from route(),
| the currency symbol and decimals from the shop's settings, the sector's own
| nouns from term(). Nothing here is hardcoded, and no currency symbol appears
| anywhere in this directory.
*/

import offlineQueue from './panel';
import { offlineConfig } from './config';
import { interceptCheckout, mirrorCart } from './till';
import { broadcast } from './sync';

export { default as offlineQueue } from './panel';

export function startOfflineQueue() {
    const config = offlineConfig();

    if (!config || !('indexedDB' in window)) {
        /*
         * No till on this page, or a browser with no IndexedDB. The second case
         * matters: without storage there is nowhere to hold a sale, so nothing
         * is started and the checkout keeps its old behaviour of failing loudly
         * rather than appearing to succeed.
         */
        return;
    }

    // Keep the mirrored cart in step, so the service worker can queue a submit
    // this page did not catch. The till raises this after every cart render.
    mirrorCart();
    window.addEventListener('salespoint:cart-rendered', mirrorCart);

    interceptCheckout(config, () => {
        window.dispatchEvent(new CustomEvent('salespoint:offline-queued'));
        broadcast();
    });

    /*
     * The service worker tells the page when it has queued a sale itself, so the
     * indicator is right even though the submit never came through this script.
     */
    navigator.serviceWorker?.addEventListener('message', (event) => {
        if (event.data?.type === 'OFFLINE_SALE_QUEUED') {
            window.dispatchEvent(new CustomEvent('salespoint:offline-queued'));
            broadcast();
        }
    });
}

export default offlineQueue;
