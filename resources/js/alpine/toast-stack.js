/*
| toastStack
|
| One host for every toast. It starts with whatever the server flashed (handed
| over by layouts/partials/toasts.blade.php) and then listens for the `toast`
| window event, which is what window.toast() dispatches.
|
| Toasts are removed from the DOM when they expire rather than merely hidden,
| so a long shift at the till does not accumulate hundreds of dead nodes.
*/
export default (initial = []) => ({
    toasts: [],
    nextId: 1,

    init() {
        initial.forEach((t) => this.push(t));

        window.addEventListener('toast', (e) => this.push(e.detail ?? {}));
    },

    push({ message, title = null, variant = 'info', timeout = 4000 }) {
        if (!message) {
            return;
        }

        const id = this.nextId++;

        this.toasts.push({ id, message, title, variant, show: true });

        if (timeout > 0) {
            setTimeout(() => this.dismiss(id), timeout);
        }
    },

    dismiss(id) {
        const toast = this.toasts.find((t) => t.id === id);

        if (!toast) {
            return;
        }

        // Let the leave transition run before the node goes away.
        toast.show = false;
        setTimeout(() => (this.toasts = this.toasts.filter((t) => t.id !== id)), 250);
    },

    // Error toasts are announced immediately; everything else waits its turn.
    liveness(variant) {
        return variant === 'error' ? 'assertive' : 'polite';
    },
});
