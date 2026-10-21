# Pilot lock (soft)

Opt-in, off by default. Nothing below runs, and no new table is touched, unless `PILOT_MODE=true` is set in `.env`.

## What it does
- Reads the pilot start date from **both** `.env` (`PILOT_START_DATE`) and a `settings` row, written once on first boot.
- **If the two disagree, the app locks immediately** - this is the anti-tamper check: editing `.env` alone (e.g. to push
  the start date back and reset the clock) cannot extend a pilot, because the stored copy never moves on its own.
- **If more than `PILOT_LENGTH_DAYS` (default 90) have passed, the app locks.** The earlier of the two dates is used,
  so neither source can be used to buy extra time.
- **A banner shows for the last `PILOT_WARNING_DAYS` (default 10) days** before that, on every normal page.
- **When locked, every request returns 402** with a static, self-contained "Pilot ended" page - including `/login` and
  the API. The middleware runs before session/auth, so there is no way to sign in around it.

## What it never does
- **No data is deleted, exported, or modified.** The only database write anywhere in this feature is the one-time
  seed of the stored start date, and `pilot:extend`'s deliberate rewrite of that same single value. Sales, stock,
  customers and every other table are untouched, locked or not. `tests/Feature/PilotLockTest.php` has a test that
  asserts this directly: it runs a sale, then exercises every pilot code path (lock, extend, license), and checks
  the sale and stock figures - and the row counts - have not moved.

## Getting unlocked
- **`php artisan pilot:license <key>`** - the normal path: the client pays, you (or they, by phone) run this on the
  server, no dates are touched. `pilot:license` with no key clears it.
- **`php artisan pilot:extend <days> --yes-i-am-sure`** - a deliberate, human-run override for support cases (a pilot
  that genuinely needs more time, or a `.env` edit made by mistake). The flag is required so it can never run by
  accident or be scripted into something unattended.
- **`php artisan pilot:status`** - shows whether pilot mode is on, the dates in use, days left, and why it is or
  isn't locked.

## Configuration
`.env.example` documents `PILOT_MODE`, `PILOT_START_DATE`, `PILOT_LENGTH_DAYS`, `PILOT_WARNING_DAYS` and
`PILOT_LICENSE_KEY`. See `config/pilot.php`.

---

# Till screen: independent scrolling, keyboard flow and speed

Run `npm run build` after pulling (the till's script moved from an inline block into `resources/js/pos/`). No migration.

## What changed on /pos
- **Two panes that scroll on their own** (large screens). The page fills the app shell and the products and the sale each
  scroll inside it, so scrolling a long product list no longer moves the sale and a long sale no longer pushes the catalogue
  away. The search box stays put above the product grid. On a tablet or phone the panes stack, with the product list capped
  at 55% of the screen height so the sale is never pages away.
- **Quantity focus.** Picking a product focuses that line's quantity box with the number selected; type a number and press
  **Enter** and the cursor is back in the search box. **Escape** undoes the edit. Tabbing or tapping away also sends it.
- **A scanner is not a typist.** A barcode that arrives in a burst (or is pasted) keeps the cursor in the search box so the
  next scan is not typed into a quantity box; a code or name typed by hand goes to the quantity. Timing, not a setting.
- **Keyboard selection.** Arrow keys move through the search results; Enter adds the highlighted one. If typing narrowed
  the grid to one product, Enter adds it.

## Speed (measured in a real browser at 80 ms network latency)
| | before | after |
|---|---|---|
| tap a product -> line on screen | 342 ms | ~10 ms |
| type a quantity -> line total and grand total | ~340 ms | ~1 ms |
| requests per tap | 2 (POST, then GET the panel) | 1 |
| six quick "+" taps | 6 round trips | 2 |
| search | a request per pause | none, when the catalogue fits on the page |

How: (1) every cart action returns the redrawn cart panel in the same answer (`X-Till-Render`), so no second request;
(2) the browser works out the line total and the sale totals itself (`resources/js/pos/calc.js`, a port of `SaleCalculator`)
and shows them at once, and a new line appears from the real line markup cloned from a `<template>`; (3) requests go through a
small ordered queue that sends one at a time, collapses repeated quantity edits, and draws only the last answer.

**The server is still the authority.** Every change is posted and the server's own panel replaces whatever the browser showed;
if a request fails the screen is restored to what the server holds. `tests/Feature/TillJsParityTest.php` feeds the PHP and JS
calculators the same random carts (including half-cent prices) and fails if they ever disagree.

## Behaviour worth knowing
- Charging waits for any quantity still on its way, so a sale is never charged for an older cart.
- A cash amount the cashier has typed is kept when the cart changes; the payment box only follows the total while it is
  still showing the total.
- `GET /pos` for AJAX and the cart actions share one renderer, so they cannot drift apart.
- Product cards carry `data-*` attributes (name, price, stock, sku, barcode) used for instant search and instant add.

## Tests
- `tests/Feature/TillSpeedTest.php`, `TillJsParityTest.php` (server side of the above).
- `tests/e2e/` - 46 checks in a real Chrome (layout, focus, timing, failures, charging). `tests/e2e/run.sh`; see its README.
  It uses its own throwaway SQLite database and refuses to run against anything but localhost.

---

# SalesPoint changelog

Run `php artisan migrate` after pulling (see each section for what the migrations do).

**Batch 3 also needs `npm install && npm run build`** — assets are no longer loaded from a CDN, so without a build the
app renders unstyled. See README.md.

# Batch 3: running a real shop

Ten migrations, `2026_10_14_000000` to `2026_10_14_000009`. Every one backfills existing rows so history does not
change meaning, and every `down()` is tested.

## One app, many trades

The same install is sold into very different shops, so the shop picks its **business type** in *Settings > Business*
and that preset decides the wording and which fields appear. A pharmacy sells "drugs" to "patients" and tracks batch
numbers and expiry dates; a salon sells "services" that are never counted in or out of stock; a wholesaler sells by
the carton to "accounts" that pay later; a restaurant runs tables. Ten presets ship in `config/sectors.php`, and
adding a trade is an entry in that file, not a code change.

- Fourteen optional features (stock, units, weight, variants, expiry, serials, credit, tables, kitchen, tips,
  appointments, prescriptions, loyalty, service items) can each be forced on or off per install, overriding the
  preset, so a shop is never boxed in by the type it chose.
- New helpers: `sector()`, `term()`, `feature()`. User-facing nouns come from `term('product')`, never a hardcoded word.
- **Products can be services.** `is_service` / `track_stock` mean an item genuinely has no stock rather than a stock
  level of zero, so a haircut never blocks a sale, appears on a count sheet, or shows a low-stock warning.
- Products also gained an image, a default supplier, a selling unit and pack size, batch number and expiry date,
  a reorder quantity and an active flag.

## Currency is configurable

`₦` was hardcoded in the views, so the app only really worked in one country. Amounts now go through a `money()`
helper reading the symbol, code and decimal places from *Settings > Currency*. **No new code may hardcode a symbol.**

## Customers and credit sales

A `customers` table existed but nothing used it. For many retailers, and nearly every wholesaler, selling on credit
is how the business works.

- Customer list and a printable **statement**: contact details, credit limit, balance, and a chronological ledger of
  invoices and payments with a running balance.
- Payments are recorded in two tables on purpose: `customer_payments` is the money that arrived,
  `customer_payment_allocations` records which invoices it settled. That keeps a part-payment spread across three
  invoices auditable, and lets a payment be reversed cleanly. Allocation is oldest-invoice-first, and the last
  invoice touched absorbs the rounding remainder so allocations always sum to the payment exactly.
- A credit limit of 0 means no limit. Over-limit credit is refused, and **overpaying a debt is refused** rather than
  silently leaving a credit balance the customer cannot see or spend.
- `sales.paid_amount` keeps meaning "tendered at the till when the sale was rung up", because that is what a shift's
  cash count reconciles against. Total collected on a sale is `paid_amount` plus its allocations.

## Cashier shifts and end-of-day reconciliation

The main control against till shortages. A cashier opens a shift with a float and closes it by counting the drawer;
the system shows expected against counted, split by cash, POS and transfer.

- A printable **Z report** per shift: who, when, sales, discounts, tax, refunds, voids, and expected vs counted vs
  difference per payment method, with a signature block that only appears on paper.
- Expected figures are **frozen when the shift is signed off**, so a sale backdated afterwards cannot quietly rewrite
  a reconciliation — it is flagged instead.
- One open shift per user, enforced under a lock so two simultaneous requests cannot both open one.
- Requiring an open shift before selling is a setting (`require_open_shift`), **off by default**, so existing installs
  are unaffected until a shop opts in.

## Refunding a credit sale (the seam between those two features)

Returning goods on an unpaid invoice **cancels the debt before it releases any cash**. Owe 600 of a 1,000 invoice,
bring back 300 of goods, and you simply owe 300 — nothing leaves the drawer. Bring back 800 and the debt clears and
200 is genuinely handed over. Previously the customer got the goods back *and* still owed for them in full.

`refunds.credit_applied` records the part that cancelled debt, so the till reconciliation counts only the cash that
actually left. Relatedly, a shift's expected takings now read `paid_amount - change_due` rather than
`grand_total - balance_due`: the latter stops meaning "money tendered" the moment a refund reduces a debt, and would
inflate expected cash by the amount written off.

## Stock control

Manual stock edits used to go through the product form, with no reason and no approval, so shrinkage was invisible.

- **Stock-takes**: draft, count, reconcile, approve. Printable count sheets with a blind-count mode that hides the
  system figure, live variance per line, and the cash value of the variance at the cost recorded when the sheet was
  created. Approval applies the *variance* to current stock rather than forcing stock to the counted figure, because a
  sale between counting and approving is a real movement with its own log. Approving twice is impossible; an
  unapproved sheet changes nothing. Approval needs four eyes — a manager cannot approve a sheet they counted.
- **Adjustments** with a reason (damage, theft, expiry, correction), refusing to drive stock below zero.
- **Reorder list** grouped by supplier, with suggested quantities, feeding a purchase order in one click.
- **CSV import and export** with a real dry-run: the first upload only reads, shows what would be created, updated and
  rejected with per-row errors, and refuses to import at all until rejected rows are explicitly skipped. Import never
  changes an existing product's stock — moving stock needs a reason and a trail.

## Suppliers and the purchase lifecycle

Suppliers could only be added inline from the purchase form: no list, no edit, no record of what was owed.

- Supplier list, statement and payments. The balance is **derived, never stored**, so it cannot drift.
- Purchases now have a status: an `ordered` purchase moves no stock, and stock only moves when it is **received**.
- Purchases can be **edited**, reconciling the stock difference line by line — changing a line from 10 to 7 takes 3
  back out — and refusing if the goods have already been sold.
- **Returns to supplier**, priced at the cost recorded on the purchase line rather than today's cost.

## Users, audit, security, backups

- User management is finished: edit, role change, admin password reset, and **deactivate instead of delete** so a
  user's sales history survives. A deactivated user is turned away at login *and* mid-session.
- You cannot remove your own admin role or deactivate yourself, and the last admin is protected from demotion,
  deactivation and deletion.
- **The audit log can finally be read**: it was written all over the app and had no screen. Filterable by user,
  action, table and date, with CSV export that contains no password hashes.
- **Scheduled backups** to a configurable disk or folder, with retention by age and count, a history with sizes and
  failures, and a `salespoint:backup` command. Needs a cron entry — see README.md.
- **Optional two-factor authentication** (TOTP), implemented in-tree against the RFC 6238 test vectors rather than
  adding a dependency, with encrypted secrets and single-use recovery codes. Can be required for admins.
- Receipt options: 58mm/80mm, footer text, logo, tax number, and sending a receipt by email or WhatsApp.
- Settings is now tabbed: Store, Business, Currency, Sales, Receipt, Security, Backup.

## The app works offline, and looks it

- **Tailwind, Alpine and Chart.js are bundled and self-hosted.** They were loaded from CDNs, so a till with no
  internet was unstyled and partly broken — on a local point of sale, for a market where power and network cuts are
  routine. jQuery and select2 are gone entirely: they were loaded on every page and **never initialised**.
- **`darkMode: 'class'` was missing from the Tailwind config.** The app toggles `.dark` on `<html>`, but Tailwind
  defaults to the OS media query, so the Light/Dark setting did nothing on a machine set the other way.
- **The layout only rendered `@yield('content')`**, so every page using `<x-app-layout>` — including the profile
  screen — rendered the shell with an empty body.
- Dynamic class names (`bg-{{ $color }}-100`) are spelled out literally; a build-time scanner cannot see them, so they
  would have vanished with the CDN.
- Grouped, collapsible, role-aware and sector-aware sidebar; a command palette on Ctrl/Cmd+K that reads the rendered
  nav so it can never offer a page you cannot reach; real focus-visible styles; larger tap targets for tablet use.
- **PWA**: manifest, icons, and a service worker that caches the app shell and hashed assets, with an offline page.
  Navigations are never cached (a stale till, or one user's page served to another, is worse than an honest offline
  screen) and non-GET requests are never intercepted, so a checkout always reaches the server or fails loudly.
- Shared components (`x-card`, `x-stat`, `x-badge`, `x-table`, `x-empty`, `x-toast`, `x-page-header`) and an
  open-shift indicator in the header.

## Table service, for restaurants and bars

The one genuinely different sale flow: the order is opened against a table, added to in rounds through the meal, sent
to the kitchen in batches, and only becomes money at the end.

- Floor view of every table: free or occupied, how long, whose section, and the running total.
- Prices are snapshotted when an item is ordered, so a menu re-price mid-meal does not change what the guests are
  charged. Kitchen prep tickets stamp only the unsent lines. Orders transfer between tables. Tips and service charges.
- A line the kitchen has already cooked cannot be quietly edited — removing it needs a reason and is audited.
- **Settling does not duplicate checkout.** It builds the till's session cart and redirects to the normal till, so
  stock locking, discount limits, tax, split payments, credit and shift stamping are all reused; an observer then
  links the completed sale back to the order. Two order lines of the same product are summed on handover — the cart is
  keyed by product id, so without that a second round of the same drink would be served free.
- Any staff member can add to any open table, deliberately: runners carry food they did not order and the bar adds to
  someone else's table. Cancelling an order needs a manager.

## Also fixed along the way

- The seeder added 50 fictional products on **every** `--seed`, including on a live shop. Now skipped once the
  catalogue is not empty, and `SEED_DEMO_DATA=false` turns it off.
- `AuditLog::record` ran *after* the settings write, so the one save that disables the audit log went unrecorded.
- `PurchasePolicy`, `SupplierPolicy` and `ProductPolicy` were stubs that returned `false` for everything.
- A printer test only passed on non-Windows hosts; `printEscPos()` now logs the attempt before branching on the OS.
- `README.md` was still the stock Laravel boilerplate; it is now install and deployment notes.

## The till, finished

- **Barcode scanning as the primary way in.** Focus stays in the search box, Enter adds the exact barcode (then
  SKU) match in one step, a tone confirms it and a different tone rejects an unknown code. Scanning the same code
  again increments the line rather than adding a second one.
- **Held carts**: park a sale with a note, serve somebody else, resume it later. Parking reserves no stock, so a
  resumed cart re-checks availability and prices and says what has changed.
- **Split payments**: one sale, several tenders. Change comes out of the cash portion only — you cannot give
  change from a card — and `sales.payment_method` keeps the largest tender so existing filters and reports still
  mean something. A tender row records money *kept*, not money handed over, so the rows and `paid_amount` always
  agree and a shift reconciles exactly.
- **Credit at the till**: attach a customer, take part payment, set a due date, with the credit limit checked
  before the sale and again under a lock.
- Fixed along the way: `LIKE` searches escaped `%` and `_` but never declared an `ESCAPE` clause, so on SQLite a
  search containing either found **nothing** instead of finding the literal character.

## Dashboard and reports

- **A role-aware dashboard.** A cashier sees their own takings, their shift and their recent sales — never
  margins or whole-shop profit. A manager gets takings, transactions, average basket, gross profit, cash in
  drawer, money owed, low stock and expiring items, a sales trend against the previous period, today by payment
  method, top sellers by quantity and by profit, and a reorder list. Every tile links to the report that explains
  it, and the whole thing renders on a brand-new install with no data.
- **Seven new reports**: by cashier (the staff-performance and discount view), by category, by product (margin by
  product, which the cost snapshot made possible), inventory valuation, receivables aged into buckets, payables,
  and expiry. All with CSV export.
- Charts repaint when the theme changes and degrade silently if the bundled Chart.js is missing, so an unbuilt
  page never throws. Every chart is accompanied by the same figures as text.
- `reports/sales_pdf` extended the app layout, so DomPDF was being handed the sidebar, the theme switcher and a
  `@vite` tag it cannot fetch. It is now a standalone print document.

## Selling with no internet

Power and network cuts are routine in the markets this app is sold into, so the till queues sales locally and
replays them when the connection returns.

- **One place creates a sale.** The sale-creation core came out of the till into `App\Services\CheckoutService`,
  used by both the live checkout and the replay endpoint. Two implementations of "create a sale" would drift and
  one of them would be wrong about money.
- **A queued sale is a record of intent, never of value.** Every price, total and tax is recomputed on arrival; a
  client-supplied total is ignored.
- **Replaying can never bank a sale twice.** Each sale carries a client-generated id with a unique index, and a
  replay is answered as a duplicate with the original sale's number.
- The default is **bank it and make the discrepancy visible**: the goods are gone and the money is in the drawer.
  A sale whose stock has since sold out is taken and stock goes negative with an inventory log, so a stock-take
  finds it. A price change is honoured at the price the customer agreed, bounded by a tolerance so a tampered
  queue cannot claim a 10,000 item sold for 1. But an over-limit credit sale and an over-cap discount are
  **refused** — those create a debt that may never be collected and bypass a control that would otherwise be
  worthless the moment someone pulled the network cable.
- The customer gets a provisional slip with a reference, reconciled to the real invoice number on sync and
  printed on the real receipt, so a returning customer can be matched by either number.

## More than one shop

Branch-scoped stock, staff and takings, with transfers between branches, all behind `feature('branches')` and
inert for a single-site shop.

- **One shared catalogue, stock split per branch.** A branch is a place, not a different business: the same tin
  has the same barcode and price in both shops, and a customer owes the business rather than a building. Stock
  lives in `branch_stock`, tied by the invariant `products.stock_quantity == SUM(branch_stock)` — with one branch
  those are the same number, which is why the till, stock-takes, the reorder list and every report needed no
  changes.
- Scoping is a **global Eloquent scope**, not a `where` clause repeated through dozens of controllers, because
  that approach always misses one — and a missed query in a POS means one shop selling another shop's stock.
  The six places that deliberately escape it are listed and commented.
- **Transfers** leave the source on dispatch and arrive on receipt, so stock in transit is counted in neither
  place rather than both. Receiving twice and over-sending are both refused.
- Fixed: the till checked availability against the group-wide total, so a branch could oversell into negative
  while another shop's shelf sat full.

## Variants and serial numbers

- **A variant is a product with a parent.** It therefore already has its own SKU, barcode, cost, price and stock
  level, and the till, stock-takes, reorder, purchases and every report work on it unchanged. A parent becomes a
  grouping row that is never sold. A bulk generator turns two axes (sizes × colours) into the missing
  combinations.
- **Serial / IMEI capture** per unit sold, with a post-sale entry screen and a lookup that answers "which sale
  did this serial go out on?" — the question that makes recording them worthwhile.

## Offsite backup

- The nightly dump is gzipped, **encrypted with AES-256 in a stream**, and uploaded to any S3-compatible store
  (AWS, Backblaze B2, Cloudflare R2, Wasabi, DigitalOcean Spaces, or a MinIO box in the back office).
- Chosen over emailing the dump deliberately: email has a hard attachment ceiling that a growing database crosses
  **silently**, which is the worst failure a backup can have. Email is kept as a report, attaching the file only
  when it is small and encrypted.
- Encrypt-then-MAC, verified in its own pass before a byte is written, so a wrong key or a truncated download
  refuses instead of producing plausible garbage. **The format is documented and restorable with `openssl` alone**
  — a backup you can only restore with the app that made it is not a backup.
- A failed upload never makes the local backup look failed, and a local copy is never pruned while its offsite
  copy is still owed.

## Security and robustness fixes found by review

- **A manager saving the shop's phone number switched off backups and encryption.** Checkbox settings were written
  on every save — an unchecked box is indistinguishable from one that was never rendered — and the Backup tab
  renders for admins only. Backups stopped, or started leaving the building unencrypted, and nothing said so
  because the screen that would report it is the one a manager cannot see. Backup, audit-log and 2FA settings are
  now admin-only: a role must not be able to edit its own constraints.
- **The till's product search returned every column to any cashier**, including `cost_price`, `supplier_id` and
  reorder levels — contradicting two policies that say in their own docblocks that a cashier must never see a
  cost. Both the search and scan paths now use one explicit column whitelist.
- **`CategoryPolicy` and `ExpensePolicy` were stubs denying everything**, and their controllers asked no policy at
  all. Both implemented and now enforced, with tests that fail if a `Gate::authorize` call is ever removed.
- **Uploads**: a failed write stored the literal `false` while reporting success; replaced images and logos were
  never deleted and accumulated for ever; a dimension limit was missing, so a 30000×30000 image passed the size
  cap; and a product whose file had gone rendered a broken-image icon. All fixed, with a shared `MediaService`.
- **The receipt printed a hardcoded placeholder logo**, so a shop could upload its logo, see it in Settings, and
  still print somebody else's mark on every sale.
- `storage/app/public` is created on first upload, and a missing `public/storage` symlink now shows the deployer
  a banner instead of failing invisibly — the web server answers those requests without PHP, so nothing reaches
  the log.
- `sales.created_at` and `inventory_logs.created_at` were unindexed while every table added later indexed it.

## Tests

The suite went from 159 to **1100**. Two of them exist specifically to catch the class of bug that a green suite
misses: `EmptyInstallTest` loads all 44 screens for all three roles on an empty database, asserting 200-or-403 and
no PHP notice in the body; and `UiConsistencyTest` compiles every Blade template, which catches a component tag
that silently fails to compile and makes a form field disappear while the page still returns 200.

---

# Batch 2: new features (cost snapshot, till discounts and tax, sales history, refunds and voids)

Migrations: `2026_10_13_000001_add_costs_discounts_and_refunds` adds the new columns/tables and **backfills existing sales**
(cost = the product's *current* cost, since the true historic cost was never stored; discount/tax shares are split across lines so
they still add up to each sale's total). `down()` is tested.

## Cost snapshot and real profit
- Every sale item now stores the product's cost at the moment of sale (`sale_items.cost_price`).
- **Profit & Loss is now revenue - cost of goods sold - expenses.** Previously it subtracted *stock purchases* from sales, which
  overstates losses while you are stocking up and overstates profit when you sell old stock.
  Stock bought is shown for information only; sales tax is excluded from revenue; refunds and voids reverse revenue and cost.
- Editing a product's cost later no longer changes past profit.

## Discounts and tax at the till
- Line discounts and whole-sale discounts, each as a percent or a fixed amount, from the cart.
- Sales tax / VAT rate in **Settings > Sales** (added on top of prices, charged after discounts; 0 = off).
- **Cashier discount limit** (Settings > Sales, default 10% of the sale, line + sale discounts together). Managers/admins are unlimited.
  Enforced when the discount is applied *and again at checkout*.
- All maths lives in one place (`App\Services\SaleCalculator`), used by both the cart and checkout. Browser-supplied tax/discount/total
  fields are ignored. Cart prices come from the database, so a price change is picked up immediately.
- Each line stores its share of the discount and tax, so line totals always add up to the sale total to the penny.

## Sales history, refunds, voids
- **Sales** page (all roles): managers see all sales with filters (date, cashier, status, payment, invoice); cashiers see only their own sales from today.
- **Sale detail** page with receipt reprint (does not re-fire the thermal printer).
- **Refunds** (manager/admin): pick quantities per line, choose whether goods go back to stock, give a reason and how the money was returned.
  Refund amounts include each line's share of discount and tax; the last unit takes the remainder so a full refund returns exactly what was paid.
  Damaged goods can be refunded without restocking (cost stays booked as a loss).
- **Voids** (manager/admin): reverse a whole sale in one step; only before any refund has been made.
- Dashboard and sales report are net of refunds; voided sales are not counted as transactions; the movement report shows refunds as stock coming back in.
- Receipts are now authorised: a cashier can only open their own receipts from today (previously any signed-in user could open any receipt by ID).

---

# Batch 1: bug fixes

Run `php artisan migrate` after pulling: two new migrations (product soft-deletes, Spatie role backfill).

## Critical
- **Checkout decremented the wrong product.** `$product` leaked from the validation loop, so every cart line reduced the last product's stock. Each line now locks and updates its own product, inside one transaction.
- **Route protection did nothing.** `->middleware()` was chained after `->group()`, and the Spatie `role` alias was never registered. Middleware is now applied correctly and aliased in `bootstrap/app.php`. Cashier = POS only; manager = stock/reports/expenses/settings; admin = users, backup, raw export.
- **Public self-registration** gave anyone a login. Removed; admins create users in Settings.
- **`users.role` vs Spatie roles** were two unsynced systems. The model now syncs them on save; a migration backfills existing users.
- **Users CSV export included password hashes.** Column-whitelisted; admin-only.

## High
- Checkout trusted client money values and session prices: now validated, priced from the DB, discount capped, payment method whitelisted, unique invoice numbers.
- Purchase delete used a non-existent `stock` column and a broken audit insert (always failed). Fixed; refuses if the stock was already sold.
- Purchase validation errors were swallowed by a catch-all; filters used mismatched field names; product list cached for 1h.
- Reports: wrong column names (`tax`/`discount`), last day of range excluded, "this month" ignored the year, PDF export crashed (`$summary` missing), movement report pointed at a missing view, P&L ran 3 queries per day.
- Settings page: nested `<form>`s, mismatched modal IDs (JS threw), form keys didn't match controller keys, unchecked boxes never saved, empty fields crashed (NOT NULL), arbitrary keys were stored, "Add User" posted to the guest-only register route.
- Expense update returned an empty page. Product create crashed on blank SKU/category. Deleting a category cascade-deleted its products; deleting a sold product hit an FK error (now soft-deleted).
- Backup used `env()` (null when config is cached), unescaped shell input, and put the DB password on the command line; now uses `config()` + Symfony Process, supports SQLite.

## Medium / low
- XSS in POS live search; stock errors on add-to-cart were silent; receipt/ESC-POS respect the Print Method setting and are Windows-guarded.
- Manual stock edits and opening stock now write inventory logs. Audit-log toggle, low-stock warning and auto-logout settings now actually work.
- Removed dead routes (supplier/purchase edit stubs), duplicate `searchProduct`, nonexistent `manifest.json` link, User-Agent dark-mode hack.
- Seeder is idempotent and assigns the admin role; admin credentials come from `SEED_ADMIN_*`.
