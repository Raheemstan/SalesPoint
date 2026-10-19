/*
|--------------------------------------------------------------------------
| The till (the /pos screen)
|--------------------------------------------------------------------------
|
| Three jobs, in order of how much a cashier feels them:
|
|   1. SPEED. Tapping a product shows the line, and changing a quantity shows the new
|      line total and grand total, in the same frame - the browser works the sum out
|      itself (./calc.js) and shows it at once. The request still goes to the server,
|      one at a time and in order, and the cart panel the server sends back replaces
|      whatever was shown. The server is the authority; this only stops the cashier
|      waiting to find out what it will say.
|
|   2. KEYBOARD FLOW. Pick a product -> its quantity box is focused with the number
|      selected -> type a number and press Enter -> the cursor is back in the search
|      box for the next product. A barcode scan skips the quantity box on purpose (see
|      looksLikeScan) so a scanner can keep firing.
|
|   3. HONESTY. If a request fails, the screen goes back to what the server last said.
|      Nothing here ever leaves the cashier looking at a cart the server does not hold.
|
| Config and URLs come from <script id="till-config"> in pos/index.blade.php, so no URL
| is spelled out by hand and no currency symbol is hardcoded.
*/

import { calculate, serviceCharge, formatMoney, round2 } from './calc';

export function startTill() {
    const configNode = document.getElementById('till-config');
    const search = document.getElementById('product-search');
    const grid = document.getElementById('product-list');
    const cartSection = document.getElementById('cart-section');

    if (!configNode || !search || !grid || !cartSection) {
        return null;
    }

    const cfg = JSON.parse(configNode.textContent);
    const token = document.querySelector('meta[name=csrf-token]').content;

    const money = (value) => formatMoney(value, cfg.symbol, cfg.decimals);

    // Names and SKUs are typed by people; never put one in innerHTML raw.
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));

    const toast = (message, options) => window.toast && window.toast(message, options);

    /*
    |----------------------------------------------------------------------
    | Scanner feedback
    |----------------------------------------------------------------------
    |
    | A cashier scanning a trolley is looking at the goods, not the screen, so the till
    | answers with a sound: a short high blip for a hit, two low notes for a code it does
    | not know. Generated with the Web Audio API rather than shipped as a file.
    |
    | Wrapped in try/catch throughout: a machine with no sound device, or a browser that
    | has not had a user gesture yet, must still sell.
    */
    let audio = null;

    function tone(frequency, ms, afterMs = 0) {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;

            audio = audio || new Ctx();
            const at = audio.currentTime + afterMs / 1000;
            const osc = audio.createOscillator();
            const gain = audio.createGain();

            osc.type = 'square';
            osc.frequency.value = frequency;

            // A flat square wave clicks on stop; fade it instead.
            gain.gain.setValueAtTime(0.06, at);
            gain.gain.exponentialRampToValueAtTime(0.0001, at + ms / 1000);

            osc.connect(gain).connect(audio.destination);
            osc.start(at);
            osc.stop(at + ms / 1000);
        } catch (e) {
            /* no audio: the toast still says what happened */
        }
    }

    const beepFound = () => tone(1760, 70);
    const beepUnknown = () => { tone(360, 130); tone(240, 200, 150); };

    /*
    |----------------------------------------------------------------------
    | Focus
    |----------------------------------------------------------------------
    */
    // Set when a product was tapped but its quantity box was not there yet to focus.
    let pendingFocus = null;

    function focusSearch() {
        pendingFocus = null;
        search.focus();
        search.select();
    }

    const qtyInput = (id) => cartSection.querySelector(`.update-cart-form[data-id="${id}"] [name=quantity]`);

    /** Focus a line's quantity box with the number selected, ready to be typed over. */
    function focusQty(id) {
        const input = qtyInput(id);

        if (!input) return false;

        pendingFocus = null;
        input.dataset.fresh = '1'; // still selected: the next keystroke replaces it
        input.focus();
        input.select();
        input.closest('[data-line]')?.scrollIntoView({ block: 'nearest' });

        return true;
    }

    /*
    |----------------------------------------------------------------------
    | The cart as the browser understands it
    |----------------------------------------------------------------------
    |
    | Read from the JSON snapshot the server renders into the panel (the same one the offline
    | queue uses), so the browser never has to scrape prices back out of formatted text.
    */
    let model = { lines: [], orderDiscount: null, service: null, taxRate: 0 };
    let lastHtml = cartSection.innerHTML; // what the server last said; the way back from a failure

    function readModel() {
        try {
            const snap = JSON.parse(document.getElementById('offline-cart-snapshot').textContent);

            return {
                lines: (snap.lines || []).map((l) => ({ ...l, product_id: Number(l.product_id) })),
                orderDiscount: snap.order_discount || null,
                service: snap.service_charge || null,
                taxRate: Number(snap.totals?.tax_rate || 0),
            };
        } catch (e) {
            return { lines: [], orderDiscount: null, service: null, taxRate: 0 };
        }
    }

    model = readModel();

    const lineEl = (id) => cartSection.querySelector(`[data-line="${id}"]`);
    const modelLine = (id) => model.lines.find((l) => l.product_id === Number(id));

    function setText(selector, value) {
        const el = cartSection.querySelector(selector);
        if (el) el.textContent = money(value);
    }

    /**
     * Work the sale out again from the browser's copy and show it. Used while a change is
     * still on its way to the server. If a discount makes the sum invalid it does nothing and
     * leaves the figures alone: the server's answer will explain.
     */
    function previewTotals() {
        let result;
        let service = 0;

        try {
            result = calculate(model.lines, model.orderDiscount, model.taxRate);
            service = serviceCharge(result.grand_total, model.service);
        } catch (e) {
            return;
        }

        const charged = round2(result.grand_total + service);

        model.lines.forEach((line, i) => {
            const el = lineEl(line.product_id);
            if (!el) return;

            const calc = result.lines[i];
            const total = el.querySelector('[data-line-total]');
            const discount = el.querySelector('[data-line-discount]');

            if (total) total.textContent = money(calc.paid_total);
            if (discount) discount.textContent = money(calc.line_discount);
        });

        setText('[data-total=subtotal]', result.subtotal);
        setText('[data-total=discount]', result.discount_total);
        setText('[data-total=tax]', result.tax);
        setText('[data-total=service]', service);
        setText('[data-total=grand]', charged);

        // The payment box offers to charge the total, so it follows the total - but only while it is
        // still doing that. Once the cashier has typed the cash they were handed, that amount is
        // theirs: changing a quantity does not change what the customer put on the counter.
        const form = cartSection.querySelector('#checkout-form');

        if (form && window.Alpine) {
            const data = window.Alpine.$data(form);

            if (data) {
                const followingTotal = Math.abs(Number(data.paid) - Number(data.total)) < 0.0005;

                data.total = charged;
                if (data.mode === 'single' && followingTotal) data.paid = String(charged);
            }
        }
    }

    /** A brief highlight so the eye finds the line that just changed. */
    let touched = { id: null, at: 0 };

    function flash(id) {
        touched = { id, at: Date.now() };

        const el = lineEl(id);
        if (!el) return;

        el.classList.add('ring-2', 'ring-primary-300');
        setTimeout(() => el.classList.remove('ring-2', 'ring-primary-300'), 700);
    }

    /*
    |----------------------------------------------------------------------
    | Showing a change before the server has answered
    |----------------------------------------------------------------------
    */
    function provisionalLine(line) {
        const template = document.getElementById('cart-line-template');

        if (!template) return null;

        const holder = document.createElement('div');
        holder.innerHTML = template.innerHTML
            .replaceAll('__ID__', esc(line.product_id))
            .replaceAll('__NAME__', esc(line.name));

        const el = holder.firstElementChild;

        el.dataset.pending = '1';
        el.dataset.stock = line.stock;
        el.dataset.tracks = line.tracks ? '1' : '0';
        el.querySelector('[data-line-each]').textContent = money(line.price);

        return el;
    }

    /** Returns true when the screen now shows the add (so the server's answer is just confirmation). */
    function optimisticAdd(card) {
        const id = Number(card.dataset.id);
        const stock = Number(card.dataset.stock);
        const tracks = card.dataset.tracks === '1';
        const existing = modelLine(id);
        const next = (existing ? existing.quantity : 0) + 1;

        // Over the stock we know of: show nothing, let the server refuse with its own message.
        if (tracks && next > stock) {
            return false;
        }

        if (existing) {
            existing.quantity = next;

            const input = qtyInput(id);
            if (input) {
                input.value = next;
                input.dataset.committed = String(next);
            }
        } else {
            const line = {
                product_id: id,
                name: card.dataset.name,
                price: Number(card.dataset.price),
                quantity: 1,
                discount_type: null,
                discount_value: null,
                stock,
                tracks,
            };

            const el = provisionalLine(line);
            const container = cartSection.querySelector('[data-cart-lines]');

            if (!el || !container) return false;

            model.lines.push(line);
            cartSection.querySelector('[data-cart-empty]')?.classList.add('hidden');
            container.appendChild(el);
        }

        previewTotals();
        flash(id);

        return true;
    }

    function optimisticQuantity(id, quantity) {
        const line = modelLine(id);

        if (!line) return;

        line.quantity = quantity;
        previewTotals();
    }

    function optimisticRemove(id) {
        lineEl(id)?.remove();
        model.lines = model.lines.filter((l) => l.product_id !== Number(id));
        previewTotals();
    }

    /*
    |----------------------------------------------------------------------
    | Talking to the server: one request at a time, in order
    |----------------------------------------------------------------------
    |
    | A cashier can tap faster than the network answers. The cart lives in the server's
    | session, so requests must not overlap (two writers would overwrite each other) and must
    | arrive in the order they were made. They go through this queue.
    |
    | Only the LAST answer is drawn: an earlier one describes a cart that has already moved on,
    | and drawing it would flash old quantities back over what was just typed.
    |
    | Several quantity edits to the same line that are still waiting are collapsed into one,
    | so pressing + five times fast sends one request for the final number, not five.
    */
    const tasks = [];
    let running = false;
    let idleWaiters = [];

    const busy = () => running || tasks.length > 0;

    function whenIdle() {
        return busy() ? new Promise((resolve) => idleWaiters.push(resolve)) : Promise.resolve();
    }

    /**
     * @param {string} url
     * @param {?object} body
     * @param {{key?: string}} [options] tasks sharing a key collapse while still waiting
     * @returns {Promise<object>} the server's answer (or {failed: true})
     */
    function mutate(url, body = null, options = {}) {
        return new Promise((resolve) => {
            // Only a task at the very back of the queue may absorb this one: collapsing into an
            // earlier task would move this change ahead of whatever was queued in between.
            const last = tasks[tasks.length - 1];
            const waiting = options.key && last && last.key === options.key ? last : null;

            if (waiting) {
                waiting.body = body;
                waiting.waiters.push(resolve);
            } else {
                tasks.push({ url, body, key: options.key || null, waiters: [resolve] });
            }

            pump();
        });
    }

    async function pump() {
        if (running) return;

        running = true;

        while (tasks.length) {
            const task = tasks.shift();
            const data = await send(task);

            task.waiters.forEach((resolve) => resolve(data));

            // Only the final answer in a burst is worth drawing.
            if (!tasks.length) {
                if (data.html) {
                    applyHtml(data.html);
                } else if (data.failed) {
                    await recover();
                }
            }
        }

        running = false;

        const waiters = idleWaiters;
        idleWaiters = [];
        waiters.forEach((resolve) => resolve());
    }

    async function send(task) {
        let res;

        try {
            res = await fetch(task.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Till-Render': '1', // ask for the cart panel in the same answer
                },
                body: JSON.stringify(task.body || {}),
            });
        } catch (e) {
            toast('The till could not reach the server.', { variant: 'error' });
            return { failed: true };
        }

        const data = await res.json().catch(() => null);

        if (data === null) {
            toast('The till could not read the server\'s answer. Reload the page.', { variant: 'error' });
            return { failed: true };
        }

        if (!res.ok) {
            toast(data.message || 'That did not work.', { variant: 'error' });
        }

        return data;
    }

    /** A request never got an answer, so what is on screen may be a guess. Show what the server holds. */
    async function recover() {
        try {
            const res = await fetch(cfg.cartUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });

            if (res.ok) {
                applyHtml(await res.text());
                return;
            }
        } catch (e) {
            /* offline: fall through to the last thing the server said */
        }

        applyHtml(lastHtml);
    }

    /*
    |----------------------------------------------------------------------
    | Drawing the server's cart panel
    |----------------------------------------------------------------------
    |
    | The panel is replaced wholesale, so a box the cashier is typing in has to be put back
    | exactly as it was - value, focus and all - or a late answer would eat their keystrokes.
    */
    function fieldSelector(el) {
        if (el.id) return `#${CSS.escape(el.id)}`;

        const form = el.closest('form');
        if (!form || !el.name) return null;

        const scope = form.dataset.id ? `form.${[...form.classList].find((c) => c.endsWith('-form'))}[data-id="${form.dataset.id}"]`
            : (form.id ? `#${CSS.escape(form.id)}` : `form.${[...form.classList].find((c) => c.endsWith('-form'))}`);

        return `${scope} [name="${CSS.escape(el.name)}"]`;
    }

    function captureFocus() {
        const el = document.activeElement;

        if (!el || el === document.body || !cartSection.contains(el)) {
            return { kind: el && el !== document.body ? 'elsewhere' : 'lost' };
        }

        if (el.matches('.update-cart-form [name=quantity]')) {
            return { kind: 'qty', id: el.closest('form').dataset.id, value: el.value, fresh: el.dataset.fresh === '1' };
        }

        if (el.matches('input:not([type=checkbox]):not([type=radio]), textarea')) {
            const selector = fieldSelector(el);

            if (selector) return { kind: 'field', selector, value: el.value };
        }

        // A button (the steppers, Remove): the click is done; carry on from the search box.
        return { kind: 'lost' };
    }

    function restoreFocus(kept) {
        if (kept.kind === 'elsewhere') return;

        if (kept.kind === 'qty') {
            const input = qtyInput(kept.id);

            if (!input) return focusSearch();

            // The box keeps what the cashier typed, even if the server has not heard it yet.
            if (input.value !== kept.value) {
                input.value = kept.value;
            }

            kept.fresh ? focusQty(kept.id) : input.focus();

            return;
        }

        if (kept.kind === 'field') {
            const field = cartSection.querySelector(kept.selector);

            if (field) {
                if (field.value !== kept.value) {
                    field.value = kept.value;
                    field.dispatchEvent(new Event('input', { bubbles: true })); // keep Alpine's model in step
                }

                field.focus();

                return;
            }
        }

        focusSearch();
    }

    let renderToken = 0;

    function applyHtml(html) {
        const kept = captureFocus();
        const mine = ++renderToken;

        cartSection.innerHTML = html;
        lastHtml = html;
        model = readModel();

        // Straight back to where the cashier was, with no gap in which a keystroke could be lost.
        restoreFocus(kept);

        // A quantity typed but not yet sent still deserves its sum.
        const dirty = [...cartSection.querySelectorAll('.update-cart-form [name=quantity]')]
            .filter((input) => input.value !== input.dataset.committed);

        dirty.forEach((input) => {
            previewQuantity(input);
            commitTimers.set(input, setTimeout(() => commitQuantity(input), 700));
        });

        if (pendingFocus !== null) {
            const id = pendingFocus;

            if (!focusQty(id)) focusSearch();
        }

        if (touched.id !== null && Date.now() - touched.at < 700) flash(touched.id);

        /*
         * One more look a tick later, for the fields Alpine manages. It wires up the new panel in its
         * own mutation callback and sets the payment amount (x-model) to the new total; if the cashier
         * was typing in that box, put their number back over it.
         */
        if (kept.kind === 'field') {
            setTimeout(() => {
                if (mine !== renderToken) return; // a newer panel has been drawn since

                const field = cartSection.querySelector(kept.selector);

                if (field && field.value !== kept.value) {
                    field.value = kept.value;
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }, 0);
        }

        // The offline queue re-reads the cart snapshot whenever this fires.
        window.dispatchEvent(new CustomEvent('salespoint:cart-rendered'));
    }

    // The cart partial's Alpine components (the customer picker) use these.
    window.tillPost = (url, body) => mutate(url, body);
    window.reloadCart = async () => { await whenIdle(); await recover(); };

    /*
    |----------------------------------------------------------------------
    | Quantities
    |----------------------------------------------------------------------
    */
    const commitTimers = new Map();

    const parseQuantity = (input) => {
        const q = parseInt(input.value, 10);

        return Number.isFinite(q) && q >= 1 ? q : null;
    };

    /** Typing: show the new sum at once; nothing is sent until the cashier is done. */
    function previewQuantity(input) {
        const q = parseQuantity(input);

        if (q === null) return;

        optimisticQuantity(input.closest('form').dataset.id, q);
    }

    function commitQuantity(input) {
        const id = input.closest('form')?.dataset.id;

        clearTimeout(commitTimers.get(input));

        if (!id) return;

        const q = parseQuantity(input) ?? 1;
        input.value = q;

        // Nothing new to tell the server.
        if (String(q) === input.dataset.committed) return;

        input.dataset.committed = String(q);
        optimisticQuantity(id, q);
        mutate(`${cfg.pos}/update/${id}`, { quantity: q }, { key: `qty:${id}` });
    }

    /** Send any quantity that was typed and left. Called before the sale is charged. */
    function flushQuantities() {
        cartSection.querySelectorAll('.update-cart-form [name=quantity]').forEach((input) => {
            if (input.value !== input.dataset.committed) commitQuantity(input);
        });
    }

    /*
    |----------------------------------------------------------------------
    | Adding a product
    |----------------------------------------------------------------------
    */
    function addProduct(card, { focusQuantity = true } = {}) {
        const shown = optimisticAdd(card);

        if (shown) beepFound();

        if (!focusQuantity) {
            focusSearch();
        } else if (!focusQty(card.dataset.id)) {
            // The line is not on screen yet (the add could not be shown early): focus it when the
            // server's cart arrives, or go back to the search box if the add was refused.
            pendingFocus = card.dataset.id;
        }

        return mutate(`${cfg.pos}/add/${card.dataset.id}`).then((data) => {
            if (data && data.success && !shown) beepFound();

            return data;
        });
    }

    /*
    |----------------------------------------------------------------------
    | The catalogue: searching and scanning
    |----------------------------------------------------------------------
    */
    const initialGrid = grid.innerHTML; // restores the full grid when the search box is emptied (server mode)
    const cards = () => [...grid.querySelectorAll('.add-to-cart-form')];
    const visibleCards = () => cards().filter((card) => !card.hidden);

    let highlighted = -1;

    function setHighlight(index) {
        const list = visibleCards();

        cards().forEach((card) => card.querySelector('button')?.classList.remove('ring-2', 'ring-primary-500'));
        highlighted = list.length ? Math.max(-1, Math.min(index, list.length - 1)) : -1;

        if (highlighted >= 0) {
            const button = list[highlighted].querySelector('button');
            button.classList.add('ring-2', 'ring-primary-500');
            list[highlighted].scrollIntoView({ block: 'nearest' });
        }
    }

    function noMatchNote(show) {
        let note = grid.querySelector('[data-no-match]');

        if (show && !note) {
            note = document.createElement('p');
            note.dataset.noMatch = '1';
            note.className = 'col-span-full py-6 text-center text-gray-500 dark:text-gray-400';
            note.textContent = cfg.noMatches;
            grid.appendChild(note);
        }

        if (note) note.hidden = !show;
    }

    /** The whole catalogue is on the page, so searching it needs no request at all. */
    function filterLocal(query) {
        const q = query.trim().toLowerCase();
        let shown = 0;

        cards().forEach((card) => {
            const match = q === '' || card.dataset.search.includes(q);
            card.hidden = !match;
            if (match) shown++;
        });

        noMatchNote(shown === 0);
        setHighlight(-1);
    }

    let searchAbort = null;

    /** More products than fit on the page: ask the server, cancelling any answer that is out of date. */
    async function filterRemote(query) {
        const q = query.trim();

        searchAbort?.abort();

        if (q === '') {
            grid.innerHTML = initialGrid;
            setHighlight(-1);
            return;
        }

        searchAbort = new AbortController();

        try {
            const res = await fetch(`${cfg.searchUrl}?q=${encodeURIComponent(q)}`, {
                headers: { 'Accept': 'application/json' },
                signal: searchAbort.signal,
            });

            renderProducts(res.ok ? await res.json() : []);
        } catch (e) {
            /* aborted, or a failed filter that is not worth a toast: the grid simply does not change */
        }
    }

    function renderProducts(products) {
        setHighlight(-1);

        if (!products.length) {
            grid.innerHTML = `<p class="col-span-full py-6 text-center text-gray-500 dark:text-gray-400">${esc(cfg.noMatches)}</p>`;
            return;
        }

        grid.innerHTML = products.map((product) => {
            // A service has no stock level, so it shows no figure and no warning.
            const tracks = !!product.track_stock && !product.is_service;
            const stock = Number(product.stock_quantity);
            const soldOut = tracks && stock <= 0;
            const low = tracks && cfg.lowStockOn && stock <= cfg.lowStockLimit;

            const note = !tracks
                ? `<span class="text-xs text-gray-400 dark:text-gray-500">${esc(cfg.serviceLabel)}</span>`
                : `<span class="text-xs text-gray-500 dark:text-gray-400">${soldOut ? 'Sold out' : esc(stock) + ' in stock'}`
                    + `${low && !soldOut ? ' <span class="font-semibold text-amber-600 dark:text-amber-400">(low)</span>' : ''}</span>`;

            const haystack = `${product.name} ${product.sku ?? ''} ${product.barcode ?? ''}`.trim().toLowerCase();

            return `
                <form method="POST" action="${cfg.pos}/add/${esc(product.id)}" class="add-to-cart-form"
                      data-id="${esc(product.id)}" data-name="${esc(product.name)}" data-price="${esc(product.sale_price)}"
                      data-stock="${esc(stock)}" data-tracks="${tracks ? 1 : 0}"
                      data-sku="${esc(product.sku)}" data-barcode="${esc(product.barcode)}" data-search="${esc(haystack)}">
                    <button type="submit" ${soldOut ? 'disabled' : ''}
                            class="flex h-full min-h-touch w-full flex-col items-start gap-1 rounded-xl border border-gray-200 bg-white p-3
                                   text-left transition hover:border-primary-400 hover:bg-primary-50 active:scale-[0.98]
                                   disabled:cursor-not-allowed disabled:opacity-50
                                   dark:border-gray-600 dark:bg-gray-700 dark:hover:border-primary-500 dark:hover:bg-gray-600">
                        <span class="line-clamp-2 font-semibold leading-snug text-gray-800 dark:text-gray-100">${esc(product.name)}</span>
                        <span class="text-base font-bold text-primary-700 dark:text-primary-300">${esc(money(product.sale_price))}</span>
                        ${note}
                    </button>
                </form>`;
        }).join('');
    }

    function resetSearch() {
        search.value = '';
        typing = { first: 0, last: 0, pasted: false };
        cfg.catalogueComplete ? filterLocal('') : filterRemote('');
    }

    /*
    |----------------------------------------------------------------------
    | Telling a scanner from a person
    |----------------------------------------------------------------------
    |
    | Both end in Enter. The difference is what happens next. A scanner is about to fire again,
    | so focus must stay in the search box or the next barcode would be typed into a quantity box.
    | A person who typed "cem" and pressed Enter wants to set the quantity.
    |
    | A scanner types a whole code in a few milliseconds (or pastes it in one go); a person does
    | not. A single character is never a barcode.
    */
    let typing = { first: 0, last: 0, pasted: false };

    function looksLikeScan() {
        const length = search.value.trim().length;

        if (typing.pasted) return true;
        if (length < 4 || !typing.first) return false;

        return (typing.last - typing.first) / (length - 1) < 60; // ms per character
    }

    search.addEventListener('input', (event) => {
        const now = performance.now();

        if (search.value === '') {
            typing = { first: 0, last: 0, pasted: false };
        } else {
            typing.first = typing.first || now;
            typing.last = now;
            typing.pasted = typing.pasted || /paste|drop/i.test(event.inputType || '');
        }

        if (cfg.catalogueComplete) {
            filterLocal(search.value);
        } else {
            clearTimeout(search.remoteTimer);
            search.remoteTimer = setTimeout(() => filterRemote(search.value), 150);
        }
    });

    /** The product a code names exactly, from the cards on the page. */
    function exactMatch(code) {
        const wanted = code.trim().toLowerCase();

        return cards().find((card) => card.dataset.barcode?.toLowerCase() === wanted && wanted !== '')
            || cards().find((card) => card.dataset.sku?.toLowerCase() === wanted && wanted !== '');
    }

    async function enter() {
        const code = search.value.trim();
        const scanned = looksLikeScan();

        // Arrow-keyed to a result: that is the product.
        if (highlighted >= 0) {
            const card = visibleCards()[highlighted];

            if (card && !card.querySelector('button').disabled) {
                resetSearch();
                addProduct(card, { focusQuantity: true });
            }

            return;
        }

        if (code === '') return;

        // 1. A code the page already knows: no waiting for anyone.
        const exact = exactMatch(code);

        if (exact && !exact.querySelector('button').disabled) {
            resetSearch();
            await addProduct(exact, { focusQuantity: !scanned });
            return;
        }

        // 2. Typed words that narrowed the grid to one product: that is the one they meant.
        const shown = visibleCards();

        if (!exact && shown.length === 1 && !shown[0].querySelector('button').disabled && !scanned) {
            resetSearch();
            addProduct(shown[0], { focusQuantity: true });
            return;
        }

        // 3. The server knows catalogue the page does not (and says "not found" properly).
        const data = await mutate(cfg.scanUrl, { code });

        if (data.found && data.success) {
            beepFound();
            resetSearch();
            scanned ? focusSearch() : focusQty(data.product?.id);
            return;
        }

        beepUnknown();
        toast(data.message || 'Could not scan that code.', { variant: data.found ? 'error' : 'warning' });

        if (data.found === false) {
            // Nothing matched exactly, so show what nearly did instead of leaving the cashier
            // wondering if the scanner is broken.
            renderProducts(data.matches || []);
            search.select();
        }
    }

    search.addEventListener('keydown', (event) => {
        switch (event.key) {
            case 'Enter':
                // Never submit a form or open a picker.
                event.preventDefault();
                enter();
                break;

            case 'ArrowDown':
                event.preventDefault();
                setHighlight(highlighted + 1);
                break;

            case 'ArrowUp':
                event.preventDefault();
                setHighlight(highlighted - 1);
                break;
        }
    });

    /*
    |----------------------------------------------------------------------
    | Cart actions
    |----------------------------------------------------------------------
    |
    | Delegated from the document, so the cart panel can be replaced wholesale without
    | anything needing to be rebound. The checkout and hold forms are deliberately not
    | listed: those are ordinary posts that navigate.
    */
    function sendDiscount(form, url, override) {
        return mutate(url, override || {
            type: form.querySelector('[name=type]').value,
            value: form.querySelector('[name=value]').value || 0,
        });
    }

    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (form.matches('.add-to-cart-form')) {
            event.preventDefault();
            addProduct(form, { focusQuantity: true });
        } else if (form.matches('.update-cart-form')) {
            event.preventDefault();
            commitQuantity(form.querySelector('[name=quantity]'));
        } else if (form.matches('.remove-cart-form')) {
            event.preventDefault();
            optimisticRemove(form.dataset.id);
            mutate(`${cfg.pos}/remove/${form.dataset.id}`);
        } else if (form.matches('.line-discount-form')) {
            event.preventDefault();
            sendDiscount(form, `${cfg.pos}/discount/${form.dataset.id}`);
        } else if (form.matches('.order-discount-form')) {
            event.preventDefault();
            sendDiscount(form, `${cfg.pos}/discount`);
        } else if (form.matches('.clear-cart-form')) {
            event.preventDefault();
            mutate(`${cfg.pos}/clear`);
        }
    });

    /*
     * Charging has to wait for the cart to settle. The sale is a normal form post, and it
     * could otherwise overtake a quantity change still in the queue and charge for the old cart.
     * Registered after the offline queue's own capture listener, so an offline sale is still
     * queued from the snapshot first.
     */
    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (!form.matches('#checkout-form')) return;

        flushQuantities();

        if (!busy()) return;

        event.preventDefault();
        event.stopPropagation();

        // By the time the cart has settled the server's answer has replaced the checkout form,
        // so submit the form that is on the page *now*: submitting the old one, which is no longer
        // in the document, silently does nothing and the sale would never go through.
        whenIdle().then(() => {
            const current = document.getElementById('checkout-form');

            current?.requestSubmit(current.querySelector('button[type=submit]') || undefined);
        });
    }, true);

    document.addEventListener('click', (event) => {
        const stepper = event.target.closest('[data-step]');

        if (stepper) {
            const form = stepper.closest('.update-cart-form');
            const input = form.querySelector('[name=quantity]');
            const step = parseInt(stepper.dataset.step, 10);

            input.value = Math.max(1, (parseInt(input.value, 10) || 1) + step);
            commitQuantity(input);

            return;
        }

        const off = event.target.closest('[data-clear-discount]');

        if (off) {
            const form = off.closest('.line-discount-form');
            sendDiscount(form, `${cfg.pos}/discount/${form.dataset.id}`, { type: 'none', value: 0 });
        }
    });

    /*
    | The quantity box.
    |
    |   typing   -> the line and the totals update as you type
    |   Enter    -> send it, and go back to the search box for the next product
    |   Escape   -> put the old number back and go back to the search box
    |   leaving  -> send it (tabbing or tapping elsewhere must not lose the number)
    */
    document.addEventListener('input', (event) => {
        const input = event.target;

        if (!input.matches('.update-cart-form [name=quantity]')) return;

        input.dataset.fresh = '0';
        previewQuantity(input);

        // Typed and then left alone: send it, so a cashier who just goes to Charge is covered.
        clearTimeout(commitTimers.get(input));
        commitTimers.set(input, setTimeout(() => commitQuantity(input), 700));
    });

    document.addEventListener('change', (event) => {
        if (event.target.matches('.update-cart-form [name=quantity]')) commitQuantity(event.target);
    });

    document.addEventListener('keydown', (event) => {
        const input = event.target;

        if (!input.matches || !input.matches('.update-cart-form [name=quantity]')) return;

        if (event.key === 'Enter') {
            event.preventDefault();
            commitQuantity(input);
            focusSearch();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            input.value = input.dataset.committed;
            previewQuantity(input);
            focusSearch();
        }
    }, true);

    /*
    |----------------------------------------------------------------------
    | Keyboard
    |----------------------------------------------------------------------
    |
    | Function keys on purpose: they do not type a character, so they work from inside the
    | amount box as well as from the grid, and they are what a cashier moving from another
    | till already expects. The on-screen list is the cheat sheet (F1).
    */
    function submitTillForm(id, refusal) {
        const form = document.getElementById(id);

        if (!form) {
            toast(refusal, { variant: 'warning' });
            return;
        }

        const button = form.querySelector('button[type=submit]');

        if (button && button.disabled) {
            toast(refusal, { variant: 'warning' });
            return;
        }

        form.requestSubmit(button || undefined);
    }

    window.addEventListener('keydown', (event) => {
        if (event.ctrlKey || event.altKey || event.metaKey) return;

        switch (event.key) {
            case 'F1':
                event.preventDefault();
                window.dispatchEvent(new CustomEvent('till-help'));
                break;

            case 'F2':
                event.preventDefault();
                focusSearch();
                break;

            case 'F4':
                event.preventDefault();
                submitTillForm('park-form', 'There is nothing to hold.');
                break;

            case 'F8':
                event.preventDefault();
                document.querySelector('.clear-cart-form')?.requestSubmit();
                break;

            case 'F9':
                event.preventDefault();
                submitTillForm('checkout-form', 'This sale cannot be taken yet.');
                break;

            case 'Escape':
                if (document.activeElement === search && search.value !== '') {
                    resetSearch();
                }
                break;
        }
    });

    focusSearch();

    return { mutate, whenIdle, focusSearch, focusQty };
}
