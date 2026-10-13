/*
|--------------------------------------------------------------------------
| Progressive web app wiring
|--------------------------------------------------------------------------
|
| Registers public/sw.js and keeps the browser's install prompt for the
| "Install app" button in the header.
|
| The registration URL carries ?v=<build token>. A service worker is replaced
| when its script URL or bytes change, so a new build gets a new worker, which
| in turn names its caches after that token and deletes the previous ones. That
| is the whole cache-versioning strategy: no deploy can serve stale assets, and
| sw.js itself never has to be rewritten.
|
| Scope note: the worker caches the shell and static assets only. It never
| caches a page or an API response, so it cannot serve one cashier's data to
| another or a stale till. Offline sale queueing is a separate job.
|
*/

// Capture the install prompt the moment it fires - usually before Alpine has
// booted, which is why it is stashed on window rather than in a component.
window.__salespointInstallPrompt = null;

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    window.__salespointInstallPrompt = e;
    window.dispatchEvent(new CustomEvent('salespoint:installable'));
});

window.addEventListener('appinstalled', () => {
    window.__salespointInstallPrompt = null;
    window.dispatchEvent(new CustomEvent('salespoint:installed'));
});

if ('serviceWorker' in navigator) {
    window.addEventListener('load', async () => {
        const meta = document.head.querySelector('meta[name="sw-version"]');
        const version = meta?.content || 'dev';

        try {
            const registration = await navigator.serviceWorker.register(`/sw.js?v=${encodeURIComponent(version)}`, {
                scope: '/',
            });

            // Hand the worker the exact CSS/JS this page is using. Vite hashes
            // those filenames, so reading them off the document is the only way
            // to know them without parsing the build manifest in the worker.
            const assets = [
                ...document.querySelectorAll('link[rel="stylesheet"][href^="/build/"]'),
            ]
                .map((el) => el.href)
                .concat(
                    [...document.querySelectorAll('script[src^="/build/"]')].map((el) => el.src),
                );

            const tell = (worker) => worker?.postMessage({ type: 'PRECACHE', assets });

            tell(registration.active);
            navigator.serviceWorker.addEventListener('controllerchange', () =>
                tell(navigator.serviceWorker.controller),
            );
        } catch (err) {
            // A failed registration must never break the till.
            console.warn('Service worker registration failed:', err);
        }
    });
}
