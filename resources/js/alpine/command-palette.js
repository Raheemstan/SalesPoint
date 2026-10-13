/*
| commandPalette
|
| The header's global search. It builds its list by reading the rendered
| sidebar (every nav link carries data-nav-label / data-nav-group), so there is
| exactly one place that defines the navigation -
| layouts/partials/sidebar.blade.php - and this can never drift from it.
|
| Opens with Ctrl/Cmd+K, or "/" when focus is not already in a field (a cashier
| typing into the barcode box must not have it stolen).
*/
export default () => ({
    open: false,
    query: '',
    index: 0,
    items: [],

    // Set from data-search-url when the user is allowed to reach the catalogue:
    // a "search the catalogue for X" row at the bottom of the results.
    searchUrl: null,

    init() {
        this.searchUrl = this.$el.dataset.searchUrl || null;

        window.addEventListener('keydown', (e) => {
            const key = e.key.toLowerCase();

            if ((e.metaKey || e.ctrlKey) && key === 'k') {
                e.preventDefault();
                this.toggle();
                return;
            }

            if (key === '/' && !this.open && !this.typingInAField(e.target)) {
                e.preventDefault();
                this.show();
            }
        });
    },

    typingInAField(el) {
        return (
            el &&
            (el.isContentEditable ||
                ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName))
        );
    },

    toggle() {
        this.open ? this.hide() : this.show();
    },

    show() {
        // Read the nav fresh each time: role changes and sector terms mean the
        // links are not the same for every user or every page.
        this.items = Array.from(document.querySelectorAll('[data-nav-label]')).map((el) => ({
            label: el.dataset.navLabel,
            group: el.dataset.navGroup || '',
            href: el.getAttribute('href'),
        }));

        this.query = '';
        this.index = 0;
        this.open = true;
        this.$nextTick(() => this.$refs.input?.focus());
    },

    hide() {
        this.open = false;
    },

    get results() {
        const q = this.query.trim().toLowerCase();

        if (!q) {
            return this.items.slice(0, 8);
        }

        return this.items
            .filter((i) => `${i.group} ${i.label}`.toLowerCase().includes(q))
            .slice(0, 12);
    },

    move(by) {
        const max = this.results.length - 1;

        if (max < 0) {
            return;
        }

        this.index = Math.min(Math.max(this.index + by, 0), max);
    },

    choose() {
        const hit = this.results[this.index];

        if (hit?.href) {
            window.location.href = hit.href;
            return;
        }

        // Nothing in the nav matched: fall through to a catalogue search.
        if (this.searchUrl && this.query.trim()) {
            window.location.href = this.catalogueUrl;
        }
    },

    get catalogueUrl() {
        return this.searchUrl
            ? this.searchUrl.replace('__QUERY__', encodeURIComponent(this.query.trim()))
            : '#';
    },
});
