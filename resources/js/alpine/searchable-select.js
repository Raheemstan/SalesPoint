/*
| searchableSelect  -  the replacement for select2 (and for jQuery with it)
|
| It does the one job select2 was loaded for: type to filter a long list of
| options. Everything else select2 offered is not used anywhere in this app.
|
| It *enhances a real <select>* rather than replacing it. The select stays in
| the form, keeps its name, its `required`, its `data-*` attributes and its
| change event, so server-side validation and existing page scripts (for
| example purchases/create.blade.php reading `data-cost` off the chosen option)
| keep working untouched. The select is visually hidden but not display:none,
| because the browser refuses to validate a field it cannot show.
|
| Markup lives in resources/views/components/searchable-select.blade.php.
*/
export default () => ({
    open: false,
    query: '',
    index: 0,
    options: [],
    label: '',
    selected: -1,

    init() {
        this.select = this.$refs.select;
        this.sync();

        // Something else may change the select (a reset, a page script setting
        // a value, options injected later) - follow it.
        this.select.addEventListener('change', () => this.sync());
        this.select.form?.addEventListener('reset', () => this.$nextTick(() => this.sync()));
    },

    sync() {
        this.options = Array.from(this.select.options).map((option, i) => ({
            i,
            value: option.value,
            text: option.text.trim(),
            disabled: option.disabled,
        }));

        this.selected = this.select.selectedIndex;

        const chosen = this.select.options[this.selected];

        // An empty value is the placeholder ("-- Select Product --"), which is
        // not a choice: show the prompt instead so the field reads as unset.
        this.label = chosen && chosen.value !== '' ? chosen.text.trim() : '';
    },

    get filtered() {
        const q = this.query.trim().toLowerCase();

        // The placeholder is not worth filtering through; drop it from the list.
        const real = this.options.filter((o) => o.value !== '');

        return q ? real.filter((o) => o.text.toLowerCase().includes(q)) : real;
    },

    toggle() {
        this.open ? this.close() : this.show();
    },

    show() {
        this.open = true;
        this.query = '';
        this.index = Math.max(
            this.filtered.findIndex((o) => o.i === this.selected),
            0,
        );
        this.$nextTick(() => this.$refs.search?.focus());
    },

    close(refocus = true) {
        if (!this.open) {
            return;
        }

        this.open = false;

        if (refocus) {
            this.$refs.button?.focus();
        }
    },

    move(by) {
        const max = this.filtered.length - 1;

        if (max < 0) {
            return;
        }

        // Wrap, so holding Down walks the whole list without getting stuck.
        this.index = (this.index + by + max + 1) % (max + 1);
        this.$nextTick(() => this.$refs.list?.children[this.index]?.scrollIntoView({ block: 'nearest' }));
    },

    chooseHighlighted() {
        const hit = this.filtered[this.index];

        if (hit) {
            this.choose(hit);
        }
    },

    choose(option) {
        if (option.disabled) {
            return;
        }

        this.select.selectedIndex = option.i;

        // A real event, so page scripts bound to `change` on the select fire
        // exactly as they would for a native selection.
        this.select.dispatchEvent(new Event('change', { bubbles: true }));
        this.select.dispatchEvent(new Event('input', { bubbles: true }));

        this.sync();
        this.close();
    },

    clear() {
        const placeholder = this.options.find((o) => o.value === '');

        if (placeholder) {
            this.choose(placeholder);
        }
    },

    isChosen(option) {
        return option.i === this.selected;
    },
});
