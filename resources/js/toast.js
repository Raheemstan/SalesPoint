/*
| One way to raise a toast from anywhere.
|
| The layout renders a single toast stack listening for the `toast` window
| event, so page scripts never have to know where toasts live or what they look
| like. Server-side flash messages go through the same stack (see
| layouts/partials/toasts.blade.php), so they all look and behave the same.
*/
export function toast(message, { variant = 'info', title = null, timeout = null } = {}) {
    if (!message) {
        return;
    }

    window.dispatchEvent(
        new CustomEvent('toast', {
            detail: {
                message: String(message),
                title,
                variant,
                // Errors stay twice as long: they are usually something to act on.
                timeout: timeout ?? (variant === 'error' ? 8000 : 4000),
            },
        }),
    );
}

export default toast;
