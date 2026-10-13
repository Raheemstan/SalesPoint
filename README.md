# SalesPoint

A point-of-sale and stock-control application for small and medium retailers, built with Laravel 12.

Designed to run on the shop's own hardware — a counter PC or a tablet — with a thermal receipt printer and a
barcode scanner. It does not depend on an internet connection to make a sale.

One install serves very different trades. The shop picks its **business type** in *Settings → Business*, and that
changes the wording and which fields appear: a pharmacy tracks batch numbers and expiry dates and sells to
"patients", a salon sells "services" that are never counted in or out of stock, a wholesaler sells by the carton
on credit, a restaurant runs table orders. See `config/sectors.php`.

---

## Requirements

| | |
|---|---|
| PHP | 8.2 or newer (8.3 recommended), with `pdo_sqlite` or `pdo_mysql`, `mbstring`, `openssl`, `zip`, `gd` |
| Composer | 2.x |
| Node.js | 20 or newer — **build time only**, not needed on the server at runtime |
| Database | SQLite (default, zero setup) or MySQL/MariaDB |
| Printer | Any ESC/POS thermal printer (58 mm or 80 mm). Windows only for direct printing — see [Receipt printing](#receipt-printing) |

---

## Installation

```bash
composer install
npm install

cp .env.example .env          # on Windows: copy .env.example .env
php artisan key:generate

touch database/database.sqlite    # SQLite only; on Windows: type nul > database\database.sqlite
php artisan migrate --seed

npm run build
php artisan serve
```

Then open <http://localhost:8000>.

### The first admin

The seeder creates one admin account from your `.env`. **Set these before seeding** — never leave the defaults
on a real install:

```dotenv
SEED_ADMIN_NAME="Shop Owner"
SEED_ADMIN_EMAIL=owner@example.com
SEED_ADMIN_PASSWORD=change-this-to-something-long
SEED_DEMO_DATA=false          # true on a local install for 50 sample products
```

The seeder is idempotent, so `--seed` is safe to run again: the admin is matched on email and updated rather
than duplicated, and the sample catalogue is only created when the catalogue is empty. **There is no public
sign-up** — it was removed deliberately, because anyone who found the URL could create themselves an account.
Admins create users in *Settings → Users*.

---

## Deployment

### 1. Build the assets

Tailwind, Alpine and Chart.js are **bundled and self-hosted**, not loaded from a CDN, because a till has to keep
working when the internet does not. That means there is a build step:

```bash
npm ci
npm run build          # writes public/build/
```

`public/build/` is generated, not committed. Build it on your machine or in CI and ship the folder, or run the
build on the server once. Node is not needed at runtime. **If you skip this step the app renders with no styling.**

### 2. Point the web server at `public/`

The document root must be `public/`, never the project root — otherwise `.env`, your database and the whole
source tree are served to the world.

<details>
<summary>Apache</summary>

`public/.htaccess` is already present; enable `mod_rewrite` and allow overrides:

```apache
DocumentRoot /var/www/salespoint/public
<Directory /var/www/salespoint/public>
    AllowOverride All
    Require all granted
</Directory>
```
</details>

<details>
<summary>Nginx</summary>

```nginx
root /var/www/salespoint/public;
index index.php;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
}
```
</details>

### 3. Production environment

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://till.example.com
SESSION_DRIVER=database
```

`APP_DEBUG=true` in production leaks your configuration and stack traces on any error. `laravel/debugbar` is a
dev dependency; install with `composer install --no-dev --optimize-autoloader` so it cannot be enabled by accident.

### 4. Cache the framework

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Re-run these after **any** change to `.env` or config — a cached config ignores `.env` entirely. If a setting
seems to be ignored after a deploy, this is almost always why.

### 5. File permissions

`storage/` and `bootstrap/cache/` must be writable by the web server user. With SQLite, **the `database/`
directory itself** must be writable too, not just the file — SQLite writes a journal alongside it.

```bash
chown -R www-data:www-data storage bootstrap/cache database
chmod -R ug+rwX storage bootstrap/cache database
```

### 6. The scheduler (for automatic backups)

Scheduled backups and other periodic work need Laravel's scheduler running. One cron entry:

```cron
* * * * * cd /var/www/salespoint && php artisan schedule:run >> /dev/null 2>&1
```

On Windows, create a Task Scheduler task that runs `php artisan schedule:run` every minute. Without this,
*Settings → Backup* can still back up on demand but nothing will run on its own.

---

## Configuration

Most behaviour is configured in the app at *Settings*, not in `.env`; values are stored in the `settings` table
and read through the `setting()` helper. The groups are **Store**, **Business** (business type and feature
toggles), **Currency**, **Sales** (tax rate, cashier discount limit, low-stock threshold), **Receipt**,
**Security** (idle logout, audit log, two-factor) and **Backup**.

### Currency

Set the symbol, code and decimal places in *Settings → Currency*. Nothing in the app hardcodes a currency — all
amounts are rendered through the `money()` helper — so one install works in any country.

### Business type

*Settings → Business* chooses the trade preset and can force any individual feature on or off, overriding the
preset. Changing the business type **only** changes wording and which fields are shown; it never deletes data.

### Receipt printing

Two modes, set in *Settings → Receipt*:

- **Browser (`dom`)** — renders an HTML receipt and uses the browser's print dialogue. Works everywhere.
- **Direct ESC/POS (`escpos`)** — talks to the printer by name through a Windows print share. **This path
  requires a Windows host**; on Linux or macOS it is skipped and logged rather than failing the sale. Set the
  share name in *Settings → Receipt* (for example `POS-58`) and choose the paper width.

A reprint from the sales history never re-fires the thermal printer — only a fresh sale does.

---

## Roles

| Role | Can do |
|---|---|
| **Cashier** | The till, their own shift, their own sales from today, customer lookup |
| **Manager** | Everything above, plus products, stock, purchases, suppliers, customers, refunds, voids, reports, settings |
| **Admin** | Everything, plus user management, the audit log, backups and raw data export |

`users.role` and the Spatie permission roles are kept in sync by the `User` model on every save, so they cannot
drift apart.

---

## Testing

```bash
php artisan test                      # everything
php artisan test --filter=TillTest    # one file
```

Tests run against an **in-memory SQLite database**, so they never touch your real data and need no setup. The
suite covers the money maths (discounts, tax, refund allocation to the penny), access control for every role,
and the stock accounting.

---

## Architecture notes

A few deliberate decisions worth knowing before changing anything:

- **Money maths lives in services**, not controllers — `app/Services/SaleCalculator.php` is the single source of
  truth for discounts, tax and how both are spread across lines. Line shares always add up to the sale total
  exactly, which is what makes a partial refund return precisely what the customer paid.
- **Totals are never taken from the browser.** Checkout re-reads prices from the database and recomputes
  everything server-side; submitted totals are ignored.
- **Cost is snapshotted on every sale line.** `sale_items.cost_price` records what the goods cost *at the moment
  of sale*, so editing a product's cost later cannot rewrite past profit. Profit & Loss is therefore
  revenue − cost of goods actually sold − expenses, not revenue − stock purchased.
- **Stock changes are always logged.** Every movement writes an `inventory_logs` row with a reason, and
  significant actions write an `audit_logs` row.
- **Concurrency.** Checkout locks each product row (`lockForUpdate`) inside a transaction, so two tills cannot
  both sell the last unit.
- **Products are soft-deleted**, because a sold product still has to appear in history.

See `CHANGES.md` for the full record of what changed in each batch and why.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| Page loads with no styling | `npm run build` was not run, or `public/build/` was not deployed |
| A settings change has no effect | Config is cached — re-run `php artisan config:cache` |
| "Failed to generate backup" | With MySQL, `mysqldump` is not on the server's `PATH`; with SQLite, `database/` is not writable |
| Thermal printer does nothing | `print_method` is `dom`, the host is not Windows, or the printer share name is wrong — check `storage/logs/laravel.log` |
| 500 after deploy | `storage/` not writable, or `APP_KEY` missing (`php artisan key:generate`) |
| Scheduled backups never run | No cron entry calling `php artisan schedule:run` |

---

## License

MIT.
