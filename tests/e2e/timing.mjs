import { launch, login, BASE, sleep } from './lib.mjs';
const { browser, page } = await launch();
await login(page);
await page.goto(BASE + '/pos', { waitUntil: 'networkidle0' });
await page.evaluate(async () => { const t = document.querySelector('meta[name=csrf-token]').content; await fetch('/pos/clear', { method: 'POST', headers: { 'X-CSRF-TOKEN': t, Accept: 'application/json', 'Content-Type': 'application/json' }, body: '{}' }).then(r => r.text()); });
await page.goto(BASE + '/pos', { waitUntil: 'networkidle0' });

const starts = new Map(), durations = [], sizes = [];
let extra = 0;
page.on('request', r => { if (/\/pos\/add\//.test(r.url())) starts.set(r, Date.now()); if (/_debugbar/.test(r.url())) extra++; });
page.on('requestfinished', async r => { if (starts.has(r)) { durations.push(Date.now() - starts.get(r)); try { sizes.push((await r.response().buffer()).length); } catch {} } });

const btns = await page.$$('.add-to-cart-form button');
for (let i = 0; i < 12; i++) { await btns[i].click(); await page.keyboard.press('Enter'); await sleep(250); }
await sleep(500);
const avg = (a) => Math.round(a.reduce((x, y) => x + y, 0) / a.length);
console.log(`DEBUGBAR=${process.env.DEBUGBAR_ENABLED}  POST /pos/add: avg ${avg(durations)} ms over ${durations.length} calls | avg answer ${Math.round(avg(sizes) / 1024)} KB (uncompressed, ${sizes.length} measured) | extra /_debugbar requests: ${extra}`);
await browser.close();
