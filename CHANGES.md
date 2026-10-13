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
