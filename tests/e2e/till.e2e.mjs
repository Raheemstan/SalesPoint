/*
| End-to-end test of the till (/pos) in a real browser.
|
| WHAT IT PROVES, which the PHP tests cannot: that the page scrolls in two independent panes; that picking a
| product focuses its quantity box and Enter returns to search; that a tap shows its line within one frame
| instead of waiting on the network; that the instant totals equal the server's; that a failed request puts
| the screen back to the truth; and that charging straight after typing a quantity charges the typed one.
|
| Run it with tests/e2e/run.sh (see README.md). It WRITES DATA - it sells things and changes stock and
| settings - so run.sh points it at a throwaway SQLite database, never your own.
*/

import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch, login, BASE, sleep, setLatency } from './lib.mjs';

const APP = process.env.APP_DIR || resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const tinker = (code) => execSync(`php artisan tinker --execute='${code.replace(/'/g, "'\\''")}'`, { cwd: APP }).toString().trim();

let pass = 0, fail = 0;
const results = [];
function check(name, cond, detail = '') {
  (cond ? pass++ : fail++);
  results.push(`${cond ? 'PASS' : 'FAIL'}  ${name}${detail ? '   [' + detail + ']' : ''}`);
  console.log(results[results.length - 1]);
}
const section = (t) => console.log(`\n--- ${t}`);

// ---------------------------------------------------------------- setup
const resetStock = () => tinker('App\\Models\\Product::query()->update(["stock_quantity"=>500]); DB::table("branch_stock")->update(["stock_quantity"=>500]); echo "ok";');
resetStock();
tinker('App\\Models\\Setting::set("tax_rate","0"); echo "ok";');
const { browser, page, cdp, errors } = await launch({ width: 1366, height: 768 });
await login(page);

const reqs = [];
page.on('request', (r) => {
  const u = r.url().replace(BASE, '');
  if (u.startsWith('/pos') || u.startsWith('/customers')) reqs.push(`${r.method()} ${u}`);
});
const reqCount = (re) => reqs.filter((r) => re.test(r)).length;

async function open(vw) {
  if (vw) await page.setViewport(vw);
  await page.goto(BASE + '/pos', { waitUntil: 'networkidle0' });
  await page.evaluate(async () => {
    const t = document.querySelector('meta[name=csrf-token]').content;
    await fetch('/pos/clear', { method: 'POST', headers: { 'X-CSRF-TOKEN': t, Accept: 'application/json', 'Content-Type': 'application/json' }, body: '{}' }).then((r) => r.text());
  });
  await page.goto(BASE + '/pos', { waitUntil: 'networkidle0' });
}
const open_ = new Map();
let rid = 0;
// Only the cart's own traffic counts: the offline module polls /pos/sync/status in the background.
const isCartCall = (r) => /\/pos(\/|$|\?)/.test(r.url().replace(BASE, '')) && !/sync\/status/.test(r.url());
page.on('request', (r) => { if (isCartCall(r)) { r.__id = ++rid; open_.set(r.__id, `${r.method()} ${r.url().replace(BASE, '')}`); } });
page.on('requestfinished', (r) => open_.delete(r.__id));
page.on('requestfailed', (r) => open_.delete(r.__id));
page.on('framenavigated', (f) => { if (f === page.mainFrame()) open_.clear(); });
const settle = async () => {
  const start = Date.now();
  let quiet = 0;
  while (Date.now() - start < 15000) {
    await sleep(40);
    quiet = open_.size === 0 ? quiet + 40 : 0;
    if (quiet >= 280) return;
  }
  throw new Error('settle timed out; waiting on: ' + JSON.stringify([...open_.values()]));
};
const active = () => page.evaluate(() => {
  const a = document.activeElement;
  return { id: a.id || '', name: a.name || '', tag: a.tagName, line: a.closest?.('[data-line]')?.dataset.line || null };
});
const cardBtn = async (i) => (await page.$$('.add-to-cart-form:not([hidden]) button'))[i];
const lineCount = () => page.evaluate(() => document.querySelectorAll('#cart-section [data-line]').length);
const grand = () => page.evaluate(() => document.querySelector('#cart-section [data-total=grand]')?.textContent.trim() ?? null);
const cardInfo = (i) => page.evaluate((i) => { const c = [...document.querySelectorAll('.add-to-cart-form:not([hidden])')][i]; return { id: c.dataset.id, name: c.dataset.name, price: Number(c.dataset.price), barcode: c.dataset.barcode, sku: c.dataset.sku }; }, i);

// ================================================================ 1. LAYOUT
section('1. Independent scrolling (desktop 1366x768)');
await open({ width: 1366, height: 768 });
for (let i = 0; i < 9; i++) { await (await cardBtn(i)).click(); await page.keyboard.press('Enter'); }
await settle();
check('9 lines in the cart', (await lineCount()) === 9, 'lines=' + await lineCount());

const m0 = await page.evaluate(() => {
  const main = document.getElementById('main-content'), grid = document.getElementById('product-list');
  const pane = document.getElementById('cart-section').parentElement;
  const r = (e) => e.getBoundingClientRect();
  return {
    mainScrolls: main.scrollHeight > main.clientHeight + 1,
    gridScrolls: grid.scrollHeight > grid.clientHeight + 1, gridBottom: Math.round(r(grid).bottom),
    paneScrolls: pane.scrollHeight > pane.clientHeight + 1, paneBottom: Math.round(r(pane).bottom),
    searchTop: Math.round(r(document.getElementById('product-search')).top), vh: innerHeight,
    cartTop: Math.round(r(document.getElementById('cart-section')).top),
  };
});
check('the page itself does not scroll', !m0.mainScrolls);
check('the product list is its own scroller', m0.gridScrolls);
check('the sale panel is its own scroller', m0.paneScrolls);
check('both panes end inside the screen', m0.gridBottom <= m0.vh && m0.paneBottom <= m0.vh, `grid ${m0.gridBottom}, pane ${m0.paneBottom}, vh ${m0.vh}`);

const paneBefore = await page.evaluate(() => document.getElementById('cart-section').parentElement.scrollTop);
const g = await page.$eval('#product-list', (e) => { const r = e.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; });
await page.mouse.move(g.x, g.y); await page.mouse.wheel({ deltaY: 900 }); await sleep(250);
const m1 = await page.evaluate(() => ({
  grid: document.getElementById('product-list').scrollTop, pane: document.getElementById('cart-section').parentElement.scrollTop,
  main: document.getElementById('main-content').scrollTop, cartTop: Math.round(document.getElementById('cart-section').getBoundingClientRect().top),
  searchTop: Math.round(document.getElementById('product-search').getBoundingClientRect().top),
}));
check('scrolling the products moves only the products', m1.grid > 0 && m1.pane === paneBefore && m1.main === 0 && m1.cartTop === m0.cartTop, JSON.stringify({ ...m1, paneBefore, cartTopBefore: m0.cartTop }));
check('the search box stays in view while the products scroll', m1.searchTop === m0.searchTop && m1.searchTop >= 0);

const c = await page.$eval('#cart-section', (e) => { const r = e.parentElement.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; });
await page.mouse.move(c.x, c.y); await page.mouse.wheel({ deltaY: 700 }); await sleep(250);
const m2 = await page.evaluate(() => ({ grid: document.getElementById('product-list').scrollTop, pane: document.getElementById('cart-section').parentElement.scrollTop, main: document.getElementById('main-content').scrollTop }));
check('scrolling the sale moves only the sale', m2.pane > 0 && m2.grid === m1.grid && m2.main === 0, JSON.stringify(m2));

await page.evaluate(() => { const p = document.getElementById('cart-section').parentElement; p.scrollTop = p.scrollHeight; });
const charge = await page.evaluate(() => { const b = [...document.querySelectorAll('#checkout-form button[type=submit]')][0]; const r = b.getBoundingClientRect(); return { top: r.top, bottom: r.bottom, vh: innerHeight }; });
check('the Charge button can be scrolled into reach', charge.top >= 0 && charge.bottom <= charge.vh, JSON.stringify(charge));

section('2. Stacked layout on a tablet (820x1180)');
await open({ width: 820, height: 1180 });
const s = await page.evaluate(() => ({ grid: document.getElementById('product-list').clientHeight, vh: innerHeight, mainScrolls: (() => { const m = document.getElementById('main-content'); return m.scrollHeight > m.clientHeight + 1; })() }));
check('the product list is capped so the sale is never pages away', s.grid <= s.vh * 0.55 + 2, JSON.stringify(s));
check('a stacked page scrolls as a whole', s.mainScrolls);

// ================================================================ 3. KEYBOARD FLOW
section('3. Quantity focus and Enter -> back to search (desktop)');
await open({ width: 1366, height: 768 });
const a0 = await cardInfo(0);
await (await cardBtn(0)).click();
let act = await active();
check('after picking a product the quantity box is focused', act.name === 'quantity' && act.line === a0.id, JSON.stringify(act));
check('the quantity is pre-selected, so typing replaces it', await page.evaluate(() => document.activeElement.dataset.fresh === '1'));
await page.keyboard.type('12');
check('typing 12 gives 12 (not 112)', (await page.evaluate(() => document.activeElement.value)) === '12');
const expected = (a0.price * 12).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const shown = await page.evaluate(() => document.querySelector('#cart-section [data-line-total]').textContent);
check('the line total follows the typing before anything is sent', shown.includes(expected), `${shown} vs ${expected}`);
await page.keyboard.press('Enter');
act = await active();
check('Enter sends the quantity and returns the cursor to the search box', act.id === 'product-search', JSON.stringify(act));
await settle();
await page.reload({ waitUntil: 'networkidle0' });
check('the server really holds 12', (await page.$eval('.update-cart-form [name=quantity]', (e) => e.value)) === '12');

section('3b. Re-adding a product, Enter with no change, Escape');
await (await cardBtn(0)).click();
act = await active();
check('adding the same product again focuses its (now 13th) quantity box', act.name === 'quantity' && (await page.evaluate(() => document.activeElement.value)) === '13');
await page.keyboard.press('Enter'); await settle();
const before = reqs.length;
await (await cardBtn(1)).click(); await page.keyboard.press('Enter'); await settle();
const after = reqs.slice(before);
check('Enter on an unchanged quantity sends nothing extra (just the add)', after.filter((r) => r.startsWith('POST')).length === 1, JSON.stringify(after));
await (await cardBtn(2)).click(); await page.keyboard.type('7');
await page.keyboard.press('Escape');
act = await active();
const q2 = await page.evaluate(() => [...document.querySelectorAll('.update-cart-form [name=quantity]')].pop().value);
check('Escape puts the old number back and returns to search', act.id === 'product-search' && q2 === '1', `active=${act.id} qty=${q2}`);
await settle();

// ================================================================ 4. SEARCH & SCAN
section('4. Search without requests, arrow keys, single match, scanner vs typist');
await open();
const searchReqs0 = reqCount(/pos\/search/);
const info5 = await cardInfo(5);
await page.focus('#product-search');
await page.keyboard.type(info5.name.slice(0, 4), { delay: 20 });
const vis = await page.evaluate(() => document.querySelectorAll('.add-to-cart-form:not([hidden])').length);
check('typing filters the grid instantly', vis >= 1 && vis < 50, 'visible=' + vis);
check('...with no request to the server', reqCount(/pos\/search/) === searchReqs0);
await page.keyboard.press('Escape');
check('Escape empties the box and shows everything again', (await page.evaluate(() => document.querySelectorAll('.add-to-cart-form:not([hidden])').length)) === 50);

await page.keyboard.type('zzzzqqq', { delay: 10 });
check('a search that matches nothing says so', await page.evaluate(() => /match that/.test(document.getElementById('product-list').textContent)));
await page.keyboard.press('Escape');

await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowDown');
const hl = await page.evaluate(() => [...document.querySelectorAll('.add-to-cart-form:not([hidden])')].findIndex((c) => c.querySelector('button').classList.contains('ring-primary-500')));
check('arrow keys highlight results', hl === 1, 'index=' + hl);
const second = await cardInfo(1);
await page.keyboard.press('Enter');
act = await active();
check('Enter adds the highlighted product and focuses its quantity', act.name === 'quantity' && act.line === second.id, JSON.stringify(act));
await page.keyboard.press('Enter'); await settle();

// one distinct word -> single result
const bc = await cardInfo(10);
await page.focus('#product-search');
await page.keyboard.type(bc.barcode, { delay: 110 }); // a person typing a code by hand
await page.keyboard.press('Enter');
act = await active();
check('a barcode TYPED by hand focuses the quantity box', act.name === 'quantity' && act.line === bc.id, JSON.stringify(act));
await page.keyboard.press('Enter'); await settle();

const bc2 = await cardInfo(11);
await page.focus('#product-search');
await page.keyboard.type(bc2.barcode, { delay: 0 }); // a scanner
await page.keyboard.press('Enter');
act = await active();
check('a SCANNED barcode keeps the cursor in the search box for the next scan', act.id === 'product-search', JSON.stringify(act));
await settle();
check('...and the scanned item is in the cart', await page.evaluate((id) => !!document.querySelector(`#cart-section [data-line="${id}"]`), bc2.id));

section('4b. A code the page does not know falls back to the server');
await page.focus('#product-search');
await page.keyboard.type('NOSUCHCODE999', { delay: 0 }); await page.keyboard.press('Enter'); await settle();
check('the server was asked', reqCount(/pos\/scan/) >= 1);
check('a toast says nothing is coded that', await page.evaluate(() => /Nothing is coded/.test(document.body.textContent)));

// ================================================================ 5. SPEED
section('5. Speed with 80 ms of network latency');
await setLatency(cdp, 80);
await open();
await setLatency(cdp, 80);
const mark = reqs.length;
const t1 = await page.evaluate(async () => {
  const card = document.querySelector('.add-to-cart-form:not([hidden])');
  const name = card.dataset.name;
  const t0 = performance.now();
  card.querySelector('button').click();
  while (![...document.querySelectorAll('#cart-section [data-line] p.font-semibold')].some((p) => p.textContent.trim() === name)) {
    await new Promise((r) => requestAnimationFrame(r));
    if (performance.now() - t0 > 3000) return -1;
  }
  return Math.round(performance.now() - t0);
});
check(`click -> line visible in ${t1} ms  (was 342 ms)`, t1 >= 0 && t1 < 60, t1 + ' ms');
await page.keyboard.press('Enter'); await settle();
const clickReqs = reqs.slice(mark);
check('one tap costs ONE request now (was a POST then a GET)', clickReqs.length === 1 && clickReqs[0].startsWith('POST /pos/add'), JSON.stringify(clickReqs));

const t2 = await page.evaluate(async () => {
  const input = document.querySelector('.update-cart-form [name=quantity]');
  const total = () => document.querySelector('#cart-section [data-line-total]').textContent;
  const before = total();
  input.focus(); input.select();
  const t0 = performance.now();
  input.value = '25'; input.dispatchEvent(new Event('input', { bubbles: true }));
  while (total() === before) { await new Promise((r) => requestAnimationFrame(r)); if (performance.now() - t0 > 3000) return -1; }
  return Math.round(performance.now() - t0);
});
check(`typing a quantity -> totals updated in ${t2} ms  (was ~340 ms)`, t2 >= 0 && t2 < 60, t2 + ' ms');
await page.keyboard.press('Enter'); await settle();

const m = reqs.length;
await page.evaluate(() => { const b = document.querySelector('.update-cart-form [data-step="1"]'); for (let i = 0; i < 6; i++) b.click(); });
await settle();
const stepReqs = reqs.slice(m).filter((r) => r.startsWith('POST /pos/update'));
const finalQ = await page.$eval('.update-cart-form [name=quantity]', (e) => e.value);
check('six quick + clicks send at most two requests, and the number is right', stepReqs.length <= 2 && finalQ === '31', `reqs=${stepReqs.length} qty=${finalQ}`);
await page.reload({ waitUntil: 'networkidle0' });
check('the server agrees (31)', (await page.$eval('.update-cart-form [name=quantity]', (e) => e.value)) === '31');
await setLatency(cdp, 0);

// ================================================================ 6. CORRECTNESS vs SERVER
section('6. The instant figures equal the server\'s figures (tax 7.5%, line discount 10%, sale discount 3%)');
tinker('App\\Models\\Setting::set("tax_rate","7.5"); echo "ok";');
await open();
await (await cardBtn(3)).click(); await page.keyboard.press('Enter');
await (await cardBtn(4)).click(); await page.keyboard.press('Enter'); await settle();
await page.evaluate(() => { const f = document.querySelector('.line-discount-form'); f.querySelector('[name=value]').value = '10'; f.querySelector('[name=type]').value = 'percent'; f.requestSubmit(); });
await settle();
await page.evaluate(() => { const f = document.querySelector('.order-discount-form'); f.querySelector('[name=value]').value = '3'; f.querySelector('[name=type]').value = 'percent'; f.requestSubmit(); });
await settle();
let mismatches = 0, checked = 0;
for (const q of [2, 3, 7, 11, 19, 4, 1, 8, 13, 6]) {
  const snap = await page.evaluate(async (q) => {
    const input = document.querySelector('.update-cart-form [name=quantity]');
    const read = () => ({ grand: document.querySelector('[data-total=grand]').textContent, sub: document.querySelector('[data-total=subtotal]').textContent, tax: document.querySelector('[data-total=tax]').textContent, line: document.querySelector('[data-line-total]').textContent });
    input.focus(); input.value = String(q); input.dispatchEvent(new Event('input', { bubbles: true }));
    return read();
  }, q);
  await page.keyboard.press('Enter'); await settle();
  const real = await page.evaluate(() => ({ grand: document.querySelector('[data-total=grand]').textContent, sub: document.querySelector('[data-total=subtotal]').textContent, tax: document.querySelector('[data-total=tax]').textContent, line: document.querySelector('[data-line-total]').textContent }));
  checked++;
  if (JSON.stringify(snap) !== JSON.stringify(real)) { mismatches++; console.log('   mismatch at qty', q, JSON.stringify(snap), 'vs server', JSON.stringify(real)); }
}
check(`instant figures matched the server's on all ${checked} quantity changes`, mismatches === 0, `mismatches=${mismatches}`);
check('the payment box follows the total', await page.evaluate(() => { const f = document.querySelector('#checkout-form'); const d = Alpine.$data(f); return Math.abs(Number(d.paid) - Number(d.total)) < 0.001; }));
tinker('App\\Models\\Setting::set("tax_rate","0"); echo "ok";');

// ================================================================ 7. FAILURES
section('7. When something goes wrong the screen tells the truth');
await open();
await (await cardBtn(0)).click(); await page.keyboard.press('Enter'); await settle();
const linesBefore = await lineCount();
await page.setOfflineMode(true);
await (await cardBtn(1)).click();
const shownAtOnce = await lineCount();
await sleep(1200);
const afterFail = await lineCount();
check('offline: the tap shows at once, then is taken back', shownAtOnce === linesBefore + 1 && afterFail === linesBefore, `before ${linesBefore}, at once ${shownAtOnce}, after ${afterFail}`);
check('offline: the cashier is told', await page.evaluate(() => /could not reach/i.test(document.body.textContent)));
await page.setOfflineMode(false);
await (await cardBtn(1)).click(); await page.keyboard.press('Enter'); await settle();
check('back online: it works again', (await lineCount()) === linesBefore + 1);

const lowId = tinker('$p=App\\Models\\Product::orderBy("name")->skip(20)->first(); DB::table("branch_stock")->where("product_id",$p->id)->update(["stock_quantity"=>2]); echo $p->id;');
await open();
const lowCard = await page.$(`.add-to-cart-form[data-id="${lowId}"] button`);
await lowCard.click(); await page.keyboard.press('Enter'); await settle();
await lowCard.click(); await page.keyboard.press('Enter'); await settle();
await lowCard.click(); await page.keyboard.press('Enter'); await settle(); // third unit: only 2 exist
const lowQty = await page.$eval(`.update-cart-form[data-id="${lowId}"] [name=quantity]`, (e) => e.value);
check('asking for more than is in stock is refused and the line shows the real quantity', lowQty === '2', 'qty=' + lowQty);
check('...with the shop\'s own message', await page.evaluate(() => /Only 2 of/.test(document.body.textContent)));
resetStock();

// ================================================================ 8. CHARGING
section('8. Charging straight after typing a quantity');
await setLatency(cdp, 150);
await open();
await setLatency(cdp, 150);
await (await cardBtn(2)).click(); await page.keyboard.press('Enter'); await settle();
await page.focus('.update-cart-form [name=quantity]');
await page.$eval('.update-cart-form [name=quantity]', (e) => e.select());
await page.keyboard.type('9');            // typed, NOT confirmed with Enter
const [nav] = await Promise.all([
  page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 20000 }),
  page.click('#checkout-form button[type=submit]'),
]);
const lastSale = JSON.parse(tinker('$s=App\\Models\\Sale::latest("id")->first(); echo json_encode(["qty"=>(int)$s->items->sum("quantity"),"lines"=>$s->items->count()]);'));
check('the sale was charged for the 9 that was typed, not the old quantity', lastSale.qty === 9 && lastSale.lines === 1, JSON.stringify(lastSale));
await setLatency(cdp, 0);

// ================================================================ 9. TYPING IS NOT LOST
section('9. A late answer does not eat what the cashier is typing');
await open();
await (await cardBtn(0)).click(); await page.keyboard.press('Enter'); await settle();
await page.focus('#paid_amount');
await page.keyboard.down('Control'); await page.keyboard.press('KeyA'); await page.keyboard.up('Control');
await page.keyboard.type('777');
await page.evaluate(() => document.querySelector('.update-cart-form [data-step="1"]').click()); // a change lands while typing
await settle();
const kept = await page.evaluate(() => ({ v: document.getElementById('paid_amount').value, f: document.activeElement.id, model: Alpine.$data(document.querySelector('#checkout-form')).paid }));
check('the amount being typed survives the cart redraw, with focus', kept.v === '777' && kept.f === 'paid_amount' && kept.model === '777', JSON.stringify(kept));

// Two kinds of console noise are caused on purpose above: the offline test (network down) and the
// stock-refusal test (the server answers 422). Anything else, and any uncaught exception, is a bug.
const unexpected = errors.filter((e) => !/ERR_INTERNET_DISCONNECTED/.test(e) && !/status of 422/.test(e));
console.log('\nJS errors (excluding the induced ones):', unexpected.length ? unexpected : 'none');
check('no unexpected JavaScript errors anywhere in the run', unexpected.length === 0, unexpected.join(' | ').slice(0, 200));
console.log(`\n==== ${pass} passed, ${fail} failed ====`);
await browser.close();
process.exit(fail ? 1 : 0);
