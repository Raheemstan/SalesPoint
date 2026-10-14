/*
| The server's description of this till, read once from the page.
|
| Lives in its own module so the panel and the boot code share one copy rather
| than each being handed their own: a second copy of the shop's currency symbol
| or of the sync URL is a second thing to keep in step.
|
| Rendered by resources/views/pos/offline.blade.php as a JSON script tag. Its
| absence is the signal that this page is not a till.
*/

let cached;

export function offlineConfig() {
    if (cached !== undefined) {
        return cached;
    }

    const node = document.getElementById('offline-config');

    if (!node) {
        cached = null;

        return cached;
    }

    try {
        cached = JSON.parse(node.textContent);
    } catch (error) {
        cached = null;
    }

    return cached;
}
