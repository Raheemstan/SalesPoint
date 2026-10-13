/*
| themeSwitcher
|
| Unchanged in behaviour from the version that used to be inline in
| layouts/app.blade.php: `system` follows the OS, `light`/`dark` force it, and
| the choice is saved for the signed-in user so the next page load is already
| right. It now reads the save URL and CSRF token from the DOM instead of
| having them interpolated into a <script> tag.
*/
export default () => ({
    theme: 'system',

    init() {
        this.theme = this.$el.dataset.theme || 'system';
        this.applyTheme(this.theme);

        // Keep following the OS while "system" is selected.
        this.media = window.matchMedia('(prefers-color-scheme: dark)');
        this.media.addEventListener('change', () => {
            if (this.theme === 'system') {
                this.applyTheme('system');
            }
        });
    },

    applyTheme(theme) {
        const html = document.documentElement;

        if (theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            html.classList.add('dark');
        } else {
            html.classList.remove('dark');
        }

        // Match the browser/OS chrome in standalone mode to the page.
        const meta = document.querySelector('meta[name="theme-color"]:not([media])');
        if (meta) {
            meta.content = html.classList.contains('dark') ? '#111827' : '#ffffff';
        }
    },

    async updateTheme() {
        this.applyTheme(this.theme);

        const url = this.$el.dataset.themeUrl;
        const token = document.head.querySelector('meta[name="csrf-token"]')?.content;

        if (!url || !token) {
            return;
        }

        try {
            await fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': token,
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ theme: this.theme }),
            });
        } catch (err) {
            // Offline is normal here; the theme still applied locally.
            console.warn('Theme save failed:', err);
        }
    },
});
