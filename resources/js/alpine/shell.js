const SIDEBAR_KEY = 'salespoint.sidebar';

/*
| appShell
|
| Owns the three bits of chrome state: the mobile/tablet drawer, the desktop
| icon rail, and the "install app" button.
|
| The rail is stored on <html data-sidebar> rather than in an Alpine class
| binding, because resources/css/app.css needs it before Alpine boots (see the
| inline script in layouts/app.blade.php) - otherwise a till that remembered
| the rail would paint the wide sidebar and jump.
*/
export default () => ({
    drawer: false,
    rail: false,
    installable: false,

    // Reactive, so the sidebar can mark itself inert while it is off-canvas.
    isWide: false,

    init() {
        this.rail = document.documentElement.dataset.sidebar === 'rail';

        // Close the drawer when the viewport grows past the breakpoint, so it
        // can never be left stuck open behind a now-static sidebar.
        this.wide = window.matchMedia('(min-width: 1024px)');
        this.isWide = this.wide.matches;
        this.wide.addEventListener('change', (e) => {
            this.isWide = e.matches;

            if (e.matches) {
                this.closeDrawer();
            }
        });

        // pwa.js stashes the browser's install prompt as soon as it fires,
        // which is usually before Alpine has started.
        this.installable = !!window.__salespointInstallPrompt;
        window.addEventListener('salespoint:installable', () => (this.installable = true));
        window.addEventListener('salespoint:installed', () => (this.installable = false));
    },

    toggleDrawer() {
        this.drawer ? this.closeDrawer() : this.openDrawer();
    },

    openDrawer() {
        this.drawer = true;
        document.body.classList.add('overflow-hidden');
        // Move focus into the drawer so a keyboard user is not left behind it.
        this.$nextTick(() => this.$refs.sidebar?.querySelector('a, button')?.focus());
    },

    closeDrawer() {
        if (!this.drawer) {
            return;
        }

        this.drawer = false;
        document.body.classList.remove('overflow-hidden');
        this.$refs.drawerToggle?.focus();
    },

    toggleRail() {
        this.rail = !this.rail;
        document.documentElement.dataset.sidebar = this.rail ? 'rail' : 'full';

        try {
            localStorage.setItem(SIDEBAR_KEY, this.rail ? 'rail' : 'full');
        } catch (e) {
            // Private browsing / blocked storage: the choice just will not stick.
        }
    },

    async install() {
        const prompt = window.__salespointInstallPrompt;

        if (!prompt) {
            return;
        }

        prompt.prompt();
        const { outcome } = await prompt.userChoice;

        if (outcome === 'accepted') {
            this.installable = false;
            window.__salespointInstallPrompt = null;
        }
    },
});
