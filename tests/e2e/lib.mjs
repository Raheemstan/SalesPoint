import puppeteer from 'puppeteer-core';

export const BASE = process.env.TILL_URL || 'http://127.0.0.1:8765';

// These tests sell things, change stock and change settings. They are for a throwaway local database only.
if (!/^https?:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/.test(BASE)) {
  throw new Error(`Refusing to run against ${BASE}: the end-to-end tests write data, so they only run against localhost.`);
}

/** Your own Chrome (CHROME_PATH), or the one bundled in @sparticuz/chromium. */
async function chromePath() {
  if (process.env.CHROME_PATH) return { path: process.env.CHROME_PATH, args: [] };

  const { default: chromium } = await import('@sparticuz/chromium');

  return { path: await chromium.executablePath(), args: chromium.args };
}

export async function launch({ width = 1366, height = 768, latency = 0 } = {}) {
  const chrome = await chromePath();
  const browser = await puppeteer.launch({
    args: [...chrome.args, '--no-sandbox'],
    executablePath: chrome.path,
    headless: 'shell',
    defaultViewport: { width, height },
  });
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  const cdp = await page.createCDPSession();
  // The app registers a service worker; requests it answers confuse Puppeteer's in-flight bookkeeping.
  await cdp.send('Network.enable');
  await cdp.send('Network.setBypassServiceWorker', { bypass: true });
  await setLatency(cdp, latency);
  return { browser, page, cdp, errors };
}

export async function setLatency(cdp, ms) {
  await cdp.send('Network.enable');
  await cdp.send('Network.emulateNetworkConditions', {
    offline: false, latency: ms, downloadThroughput: -1, uploadThroughput: -1,
  });
}

export async function login(page) {
  await page.goto(BASE + '/login', { waitUntil: 'networkidle0' });
  await page.type('input[name=email]', process.env.E2E_EMAIL || 'test@example.com');
  await page.type('input[name=password]', process.env.E2E_PASSWORD || 'password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type=submit]')]);
}

export const sleep = (ms) => new Promise(r => setTimeout(r, ms));
