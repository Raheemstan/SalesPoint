# Till end-to-end tests

Drives the real `/pos` screen in a real Chrome. These cover what the PHP tests cannot see: layout, focus,
timing, and what happens in the browser when a request fails.

```
npm run build          # once, from the project root: the tests load the real page
tests/e2e/run.sh
```

`run.sh` creates its own throwaway SQLite database (`/tmp/till-e2e.sqlite`), seeds it, starts the app on
port 8765, runs the checks and stops the app. It never touches the database in your `.env`: the tests sell
things and change stock, so they refuse to run against anything but `localhost`.

**Chrome.** Use your own with `CHROME_PATH="/path/to/chrome" tests/e2e/run.sh`, or let `npm install` (run
automatically on first use) fetch the bundled one.

**Seeded login.** `test@example.com` / `password`, or set `E2E_EMAIL` / `E2E_PASSWORD`
(the values you gave `SEED_ADMIN_*`).

What the 46 checks cover: the two panes scroll independently on desktop and stack on a tablet; picking a
product focuses its quantity with the number selected; Enter returns to search; Escape undoes an edit;
scanned barcodes keep the cursor in search while hand-typed ones go to the quantity; search needs no request;
a tap shows its line in under 60 ms at 80 ms of network latency, in one request; the instant totals match the
server's across tax and discounts; an offline tap is taken back; a stock refusal shows the true quantity;
charging immediately after typing charges the typed quantity; and a number being typed survives the cart
being redrawn.
