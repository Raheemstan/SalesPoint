import { gzipSync, brotliCompressSync } from 'node:zlib';
import { launch, login, BASE, sleep } from './lib.mjs';
const { browser, page } = await launch();
await login(page);
await page.goto(BASE + '/pos', { waitUntil: 'networkidle0' });
const html = await page.evaluate(async () => {
  const t = document.querySelector('meta[name=csrf-token]').content;
  await fetch('/pos/clear', { method: 'POST', headers: { 'X-CSRF-TOKEN': t, Accept: 'application/json', 'Content-Type': 'application/json' }, body: '{}' }).then(r => r.text());
  const ids = [...document.querySelectorAll('.add-to-cart-form')].slice(0, 5).map(f => f.dataset.id);
  let last;
  for (const id of ids) last = await fetch('/pos/add/' + id, { method: 'POST', headers: { 'X-CSRF-TOKEN': t, Accept: 'application/json', 'Content-Type': 'application/json', 'X-Till-Render': '1' }, body: '{}' }).then(r => r.json());
  return last.html;
});
const raw = Buffer.from(html);
console.log(`5-line cart panel: ${(raw.length / 1024).toFixed(1)} KB raw | ${(gzipSync(raw).length / 1024).toFixed(1)} KB gzip | ${(brotliCompressSync(raw).length / 1024).toFixed(1)} KB brotli`);
await browser.close();
